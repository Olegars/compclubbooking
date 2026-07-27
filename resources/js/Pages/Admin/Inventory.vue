<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { Head } from '@inertiajs/vue3'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'

// --- СОСТОЯНИЯ ---
const products = ref<any[]>([])
const categories = ['Все', 'Напитки', 'Снэки', 'Еда']
const activeCategory = ref('Все')
const isLoading = ref(true)
const scannedId = ref<number | null>(null) // Для эффекта вспышки при сканировании
const lastScannedName = ref('') // Для системного уведомления

// Модалка и Форма
const isModalOpen = ref(false)
const isProcessing = ref(false)
const imagePreview = ref<string | null>(null)
const fileInput = ref<HTMLInputElement | null>(null)

const form = ref({
    id: null as number | null,
    name: '',
    category: 'Снэки',
    price: 100,
    stock: 0,
    barcode: '',
    image: null as File | null,
    current_image_url: ''
})

// --- ЛОГИКА СКАНЕРА (AUTO-INCREMENT) ---
let barcodeBuffer = ''
let lastKeyTime = Date.now()

const handleGlobalKeyDown = async (e: KeyboardEvent) => {
    if (e.target instanceof HTMLInputElement || e.target instanceof HTMLTextAreaElement) return

    const currentTime = Date.now()
    if (currentTime - lastKeyTime > 50) barcodeBuffer = ''
    lastKeyTime = currentTime

    if (e.key === 'Enter') {
        if (barcodeBuffer.length > 5) {
            await processAutoStock(barcodeBuffer)
        }
        barcodeBuffer = ''
    } else if (e.key !== 'Shift') {
        barcodeBuffer += e.key
    }
}

const processAutoStock = async (code: string) => {
    try {
        // 1. Ищем товар
        const { data } = await axios.get('/admin/api/inventory/find-barcode', { params: { code } })

        // 2. СРАЗУ ДЕЛАЕМ +1 (Optimistic UI)
        await quickUpdateStock(data.id, 1)

        // 3. Визуальный фидбек
        lastScannedName.value = data.name
        scannedId.value = data.id

        // Скроллим к позиции
        document.getElementById(`product-${data.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })

        // Очищаем подсветку через 2 сек
        setTimeout(() => {
            scannedId.value = null
            lastScannedName.value = ''
        }, 2000)

    } catch (e: any) {
        // Если товара нет — предлагаем создать
        if (confirm(`Объект [${code}] не опознан. Зарегистрировать новую единицу в базе?`)) {
            openModal()
            form.value.barcode = code
        }
    }
}

// --- СТАНДАРТНОЕ УПРАВЛЕНИЕ ---
const fetchProducts = async () => {
    isLoading.value = true
    try {
        const { data } = await axios.get('/api/shop/products')
        products.value = data
    } catch (e) { console.error('Warehouse Link Lost') }
    finally { isLoading.value = false }
}

const quickUpdateStock = async (id: number, amount: number) => {
    const idx = products.value.findIndex(p => p.id === id)
    if (idx === -1) return
    const product = products.value[idx]
    const oldStock = Number(product.stock)

    if (oldStock + amount < 0) return

    product.stock = oldStock + amount // Мгновенное обновление
    try {
        await axios.post('/admin/api/inventory/update-stock', { id, amount })
    } catch (e) { product.stock = oldStock }
}

const openModal = (product: any = null) => {
    if (product) {
        form.value = {
            id: product.id, name: product.name, category: product.category,
            price: Math.floor(product.price), stock: Number(product.stock),
            barcode: product.barcode || '', image: null, current_image_url: product.image || ''
        }
        // Формируем корректный путь для превью старой картинки от корня домена
        imagePreview.value = product.image ? (product.image.startsWith('/') ? product.image : '/' + product.image) : null
    } else {
        form.value = { id: null, name: '', category: 'Снэки', price: 100, stock: 0, barcode: '', image: null, current_image_url: '' }
        imagePreview.value = null
    }
    isModalOpen.value = true
}

// --- ОБРАБОТКА ВЫБОРА КАРТИНКИ ---
const triggerFileInput = () => {
    fileInput.value?.click()
}

const handleFileChange = (e: Event) => {
    const target = e.target as HTMLInputElement
    if (target.files && target.files[0]) {
        const file = target.files[0]
        form.value.image = file

        // Создаем временную blob-ссылку для моментального локального превью
        imagePreview.value = URL.createObjectURL(file)
    }
}

const saveProduct = async () => {
    isProcessing.value = true
    const formData = new FormData()

    // Упаковываем все поля формы в FormData для отправки файлов через multipart/form-data
    Object.entries(form.value).forEach(([key, val]) => {
        if (val !== null) formData.append(key, val as any)
    })

    try {
        await axios.post('/admin/api/inventory/save', formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        })
        await fetchProducts()
        isModalOpen.value = false
    } catch (e) { alert('Sync Error') }
    finally { isProcessing.value = false }
}

const deleteProduct = async (id: number) => {
    if (!confirm('Снять единицу с учета и удалить из базы?')) return

    try {
        await axios.delete(`/admin/api/inventory/delete/${id}`)
        products.value = products.value.filter(p => p.id !== id)
    } catch (e) { alert('Sync Error') }
}

onMounted(() => {
    fetchProducts()
    window.addEventListener('keydown', handleGlobalKeyDown)
})
onUnmounted(() => window.removeEventListener('keydown', handleGlobalKeyDown))

const filteredProducts = computed(() => {
    if (activeCategory.value === 'Все') return products.value
    return products.value.filter(p => p.category === activeCategory.value)
})
</script>

<template>
    <Head title="LOGISTICS // AUTO-SCAN" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 relative">

            <Transition name="slide">
                <div v-if="lastScannedName" class="fixed top-24 right-10 z-[100] bg-cyan-500 text-black px-8 py-4 rounded-2xl font-black uppercase italic shadow-[0_0_50px_rgba(6,182,212,0.5)] flex items-center gap-4 border-2 border-white/20">
                    <span class="text-2xl">⚡</span>
                    <div>
                        <div class="text-[10px] leading-none opacity-70">UNIT IDENTIFIED</div>
                        <div>{{ lastScannedName }} +1 шт</div>
                    </div>
                </div>
            </Transition>

            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 bg-[#0a0a0a] border border-white/5 p-8 rounded-[2.5rem] shadow-2xl relative overflow-hidden">
                <div class="absolute inset-0 bg-gradient-to-r from-cyan-500/5 to-transparent pointer-events-none"></div>
                <div class="relative z-10">
                    <h1 class="text-4xl font-black uppercase italic text-cyan-500 tracking-tighter">Reactor <span class="text-white">Warehouse</span></h1>
                    <div class="flex items-center gap-3 mt-2">
                        <div class="w-2 h-2 bg-cyan-500 rounded-full animate-pulse shadow-[0_0_10px_#06b6d4]"></div>
                        <p class="text-white/30 text-[10px] uppercase tracking-[0.4em] font-black italic">Auto-Plus Mode: ACTIVE</p>
                    </div>
                </div>

                <div class="flex flex-wrap items-center gap-4 relative z-10">
                    <div class="flex gap-2 p-1.5 bg-white/5 rounded-2xl border border-white/5 backdrop-blur-md">
                        <button v-for="cat in categories" :key="cat" @click="activeCategory = cat"
                                class="px-5 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all"
                                :class="activeCategory === cat ? 'bg-cyan-500 text-black shadow-[0_0_20px_rgba(6,182,212,0.4)]' : 'text-white/40 hover:text-white'">
                            {{ cat }}
                        </button>
                    </div>
                    <button @click="openModal()" class="px-8 py-4 bg-cyan-500 hover:bg-cyan-400 text-black font-black uppercase rounded-2xl shadow-[0_0_30px_rgba(6,182,212,0.2)] transition-all italic text-xs">
                        + Manual Add
                    </button>
                </div>
            </div>

            <div v-if="isLoading" class="flex justify-center py-40">
                <div class="w-16 h-16 border-4 border-cyan-500/10 border-t-cyan-500 rounded-full animate-spin"></div>
            </div>

            <div v-else class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-8">
                <div v-for="item in filteredProducts" :key="item.id" :id="`product-${item.id}`"
                     class="bg-[#050505] border rounded-[3rem] p-8 group transition-all duration-500 flex flex-col relative overflow-hidden"
                     :class="[
                        item.stock <= 5 ? 'border-red-500/40 bg-red-900/5' : 'border-white/5',
                        scannedId === item.id ? 'ring-4 ring-cyan-500 border-cyan-500 scale-[1.03] z-20 shadow-[0_0_60px_rgba(6,182,212,0.4)] bg-cyan-500/5' : ''
                     ]">

                    <div v-if="scannedId === item.id" class="absolute inset-0 bg-cyan-500/10 animate-pulse pointer-events-none"></div>

                    <div class="aspect-square bg-white/5 rounded-[2.5rem] mb-6 flex items-center justify-center border border-white/5 relative overflow-hidden group-hover:bg-cyan-500/5 transition-all">
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

                    <div class="grid grid-cols-3 gap-2 mb-8 p-1.5 bg-white/5 rounded-2xl border border-white/5">
                        <button @click="quickUpdateStock(item.id, -1)" class="py-3 bg-black/40 hover:bg-red-500 hover:text-black rounded-xl text-[11px] font-black transition-all border border-white/5">-1</button>
                        <button @click="quickUpdateStock(item.id, 1)" class="py-3 bg-black/40 hover:bg-cyan-500 hover:text-black rounded-xl text-[11px] font-black transition-all border border-white/5">+1</button>
                        <button @click="quickUpdateStock(item.id, 10)" class="py-3 bg-cyan-500/10 hover:bg-cyan-500 hover:text-black text-cyan-500 rounded-xl text-[11px] font-black transition-all border border-cyan-500/20">+10</button>
                    </div>

                    <div class="mt-auto flex gap-3">
                        <button @click="openModal(item)" class="flex-1 py-4 bg-white/5 border border-white/10 rounded-2xl text-[10px] font-black uppercase text-white/30 hover:text-white transition-all">Изменить</button>
                        <button @click="deleteProduct(item.id)" class="px-5 py-4 bg-red-500/5 border border-red-500/10 text-red-500 rounded-2xl hover:bg-red-500 hover:text-black transition-all">🗑️</button>
                    </div>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="isModalOpen" class="fixed inset-0 z-[9999999] flex items-center justify-center p-6">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-2xl" @click="isModalOpen = false"></div>
                <div class="relative w-full max-w-xl bg-[#050505] border-2 border-cyan-500/30 rounded-[3.5rem] p-12 shadow-[0_0_120px_rgba(6,182,212,0.2)] animate-in zoom-in duration-300">
                    <h2 class="text-cyan-500 text-3xl font-black uppercase italic mb-10 tracking-tighter">{{ form.id ? 'Core: Update Unit' : 'Core: Register Unit' }}</h2>

                    <div class="space-y-6">

                        <div class="flex flex-col items-center justify-center border-2 border-dashed border-white/10 rounded-[2rem] p-6 bg-black/50 hover:border-cyan-500/40 transition-all cursor-pointer group" @click="triggerFileInput">
                            <input type="file" ref="fileInput" class="hidden" accept="image/*" @change="handleFileChange" />

                            <div v-if="imagePreview" class="w-32 h-32 relative rounded-xl overflow-hidden bg-white/5 border border-white/10">
                                <img :src="imagePreview" class="w-full h-full object-contain" />
                                <div class="absolute inset-0 bg-black/60 flex items-center justify-center opacity-0 group-hover:opacity-100 transition-opacity">
                                    <span class="text-[9px] text-cyan-500 font-black uppercase tracking-widest">Сменить аватар</span>
                                </div>
                            </div>

                            <div v-else class="text-center py-4">
                                <span class="text-3xl block mb-2">📸</span>
                                <span class="text-[10px] text-white/40 font-black uppercase tracking-widest group-hover:text-cyan-500 transition-colors">Загрузить аватар товара</span>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-6">
                            <div>
                                <label class="text-[10px] text-white/30 uppercase font-black mb-2 block italic">Маркировка</label>
                                <input v-model="form.name" type="text" class="w-full bg-black border border-white/10 rounded-2xl px-5 py-4 text-white font-bold focus:border-cyan-500 outline-none" />
                            </div>
                            <div>
                                <label class="text-[10px] text-white/30 uppercase font-black mb-2 block italic">Barcode Data</label>
                                <input v-model="form.barcode" type="text" placeholder="Scan now..." class="w-full bg-black border border-cyan-500/50 rounded-2xl px-5 py-4 text-cyan-500 font-bold focus:border-cyan-500 outline-none" />
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
                                <input v-model.number="form.stock" type="number" class="w-full bg-black border border-white/10 rounded-xl px-4 py-4 text-white font-bold" />
                            </div>
                        </div>
                    </div>

                    <div class="mt-12 flex gap-4">
                        <button @click="isModalOpen = false" class="flex-1 py-5 border border-white/10 text-white/30 uppercase font-black rounded-2xl hover:text-white transition-all">Abort</button>
                        <button @click="saveProduct" :disabled="isProcessing" class="flex-[2] py-5 bg-cyan-500 hover:bg-cyan-400 text-black uppercase font-black italic rounded-2xl shadow-[0_10px_30px_rgba(6,182,212,0.3)]">
                            Confirm Sync
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

/* Анимация уведомления */
.slide-enter-active, .slide-leave-active { transition: all 0.5s ease; }
.slide-enter-from { transform: translateX(100%); opacity: 0; }
.slide-leave-to { transform: translateX(100%); opacity: 0; }

input::-webkit-outer-spin-button, input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
input[type=number] { -moz-appearance: textfield; }
</style>
