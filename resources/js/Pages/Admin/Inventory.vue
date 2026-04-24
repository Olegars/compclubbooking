<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'

// --- СОСТОЯНИЯ ---
const products = ref<any[]>([])
const categories = ['Все', 'Напитки', 'Снэки', 'Еда']
const activeCategory = ref('Все')
const isLoading = ref(true)

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
    image: null as File | null,
    current_image_url: ''
})

// --- ЗАГРУЗКА ТОВАРОВ ---
const fetchProducts = async () => {
    isLoading.value = true
    try {
        // Забираем товары по API (можно использовать тот же роут, что и для магазина)
        const { data } = await axios.get('/api/shop/products')
        products.value = data
    } catch (e) {
        console.error('Ошибка загрузки склада:', e)
    } finally {
        isLoading.value = false
    }
}

const filteredProducts = computed(() => {
    if (activeCategory.value === 'Все') return products.value
    return products.value.filter(p => p.category === activeCategory.value)
})

// --- УПРАВЛЕНИЕ ФОРМОЙ ---
const openModal = (product: any = null) => {
    if (product) {
        form.value = {
            id: product.id,
            name: product.name,
            category: product.category,
            price: Math.floor(product.price),
            image: null,
            current_image_url: product.image || '/images/shop/default.png'
        }
        imagePreview.value = product.image || '/images/shop/default.png'
    } else {
        form.value = { id: null, name: '', category: 'Снэки', price: 100, image: null, current_image_url: '' }
        imagePreview.value = null
    }
    isModalOpen.value = true
}

const closeModal = () => {
    isModalOpen.value = false
    setTimeout(() => {
        form.value = { id: null, name: '', category: 'Снэки', price: 100, image: null, current_image_url: '' }
        imagePreview.value = null
        if (fileInput.value) fileInput.value.value = ''
    }, 300)
}

// --- ОБРАБОТКА ИЗОБРАЖЕНИЯ ---
const handleImageUpload = (e: Event) => {
    const target = e.target as HTMLInputElement
    if (target.files && target.files[0]) {
        const file = target.files[0]
        form.value.image = file
        // Создаем временную ссылку для предпросмотра
        imagePreview.value = URL.createObjectURL(file)
    }
}

const triggerFileInput = () => {
    if (fileInput.value) fileInput.value.click()
}

const handleImageError = (e: Event) => {
    const target = e.target as HTMLImageElement;
    target.src = '/images/shop/default.png';
}

// --- СОХРАНЕНИЕ В БАЗУ (FormData) ---
const saveProduct = async () => {
    if (!form.value.name || !form.value.price) return alert('Заполните название и цену')

    isProcessing.value = true
    const formData = new FormData()
    formData.append('name', form.value.name)
    formData.append('category', form.value.category)
    formData.append('price', form.value.price.toString())

    // Если выбрали новый файл, прикрепляем его
    if (form.value.image) {
        formData.append('image', form.value.image)
    }
    if (form.value.id) {
        formData.append('id', form.value.id.toString())
    }

    try {
        // Отправляем POST запрос. Laravel должен принять файл, сохранить в public и вернуть обновленный список или объект
        await axios.post('/admin/api/inventory/save', formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        })
        await fetchProducts()
        closeModal()
    } catch (e: any) {
        alert(e.response?.data?.message || 'Ошибка сохранения товара')
    } finally {
        isProcessing.value = false
    }
}

// --- УДАЛЕНИЕ ---
const deleteProduct = async (id: number) => {
    if (!confirm('ВНИМАНИЕ: Безвозвратно удалить позицию со склада?')) return
    try {
        await axios.delete(`/admin/api/inventory/delete/${id}`)
        await fetchProducts()
    } catch (e) {
        alert('Ошибка удаления')
    }
}

onMounted(fetchProducts)
</script>

<template>
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500">

            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6 bg-[#0a0a0a] border border-white/5 p-8 rounded-[2.5rem] shadow-xl">
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-cyan-500 tracking-tighter">Склад Маркета</h1>
                    <p class="text-white/30 text-[10px] uppercase tracking-[0.4em] font-black mt-1 italic">Управление товарной матрицей</p>
                </div>

                <div class="flex flex-wrap items-center gap-4">
                    <div class="flex gap-2 p-1 bg-white/5 rounded-2xl border border-white/5">
                        <button v-for="cat in categories" :key="cat"
                                @click="activeCategory = cat"
                                class="px-5 py-2.5 rounded-xl text-[10px] font-black uppercase tracking-widest transition-all"
                                :class="activeCategory === cat ? 'bg-cyan-500 text-black shadow-[0_0_15px_rgba(6,182,212,0.3)]' : 'text-white/40 hover:text-white'">
                            {{ cat }}
                        </button>
                    </div>
                    <button @click="openModal()" class="px-6 py-3 bg-cyan-500 hover:bg-cyan-400 text-black font-black uppercase tracking-widest rounded-2xl transition-all shadow-[0_0_20px_rgba(6,182,212,0.2)] active:scale-95 italic text-xs">
                        + Добавить товар
                    </button>
                </div>
            </div>

            <div v-if="isLoading" class="flex justify-center py-20">
                <div class="w-12 h-12 border-4 border-cyan-500/20 border-t-cyan-500 rounded-full animate-spin"></div>
            </div>

            <div v-else-if="filteredProducts.length > 0" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <div v-for="item in filteredProducts" :key="item.id"
                     class="bg-[#050505] border border-white/5 rounded-[2.5rem] p-6 group hover:border-cyan-500/30 transition-all flex flex-col shadow-lg relative overflow-hidden">

                    <div class="aspect-square bg-white/5 rounded-[2rem] mb-6 flex items-center justify-center border border-white/5 relative overflow-hidden group-hover:bg-cyan-500/5 transition-colors">
                        <img :src="item.image || '/images/shop/default.png'" @error="handleImageError"
                             class="w-3/4 h-3/4 object-contain transition-transform duration-500 group-hover:scale-110 drop-shadow-2xl" />
                    </div>

                    <div class="flex justify-between items-start mb-2">
                        <div class="text-sm font-black text-white uppercase italic tracking-tight pr-4">{{ item.name }}</div>
                        <div class="text-xl font-black text-cyan-500 italic shrink-0">{{ Math.floor(item.price) }}<span class="text-[10px] ml-0.5">₽</span></div>
                    </div>

                    <div class="text-[9px] text-white/30 uppercase font-black tracking-widest mb-6">{{ item.category }}</div>

                    <div class="mt-auto grid grid-cols-2 gap-2 opacity-0 group-hover:opacity-100 transition-opacity translate-y-2 group-hover:translate-y-0">
                        <button @click="openModal(item)" class="py-3 bg-white/5 border border-white/10 hover:border-cyan-500/50 hover:text-cyan-500 rounded-xl text-[10px] font-black uppercase text-white/50 transition-all">
                            Изменить
                        </button>
                        <button @click="deleteProduct(item.id)" class="py-3 bg-red-500/5 border border-red-500/20 hover:bg-red-500 hover:text-black rounded-xl text-[10px] font-black uppercase text-red-500 transition-all">
                            Удалить
                        </button>
                    </div>
                </div>
            </div>

            <div v-else class="py-32 text-center border border-dashed border-white/5 rounded-[3rem] bg-black/50">
                <p class="text-white/20 uppercase font-black italic tracking-[0.5em]">Пустой отсек склада</p>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="isModalOpen" class="fixed inset-0 z-[9999999] flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-md" @click="closeModal"></div>
                <div class="relative w-full max-w-lg bg-[#050505] border-2 border-cyan-500/30 rounded-[3rem] p-10 shadow-[0_0_100px_rgba(6,182,212,0.15)] animate-in zoom-in duration-300 overflow-hidden">

                    <h2 class="text-cyan-500 text-2xl font-black uppercase italic mb-8">
                        {{ form.id ? 'Редактировать товар' : 'Новая позиция' }}
                    </h2>

                    <div class="space-y-6">
                        <div class="flex gap-6 items-center">
                            <div @click="triggerFileInput"
                                 class="w-32 h-32 rounded-[2rem] bg-black border-2 border-dashed border-white/20 hover:border-cyan-500 flex flex-col items-center justify-center cursor-pointer relative overflow-hidden group transition-all shrink-0">
                                <img v-if="imagePreview" :src="imagePreview" @error="handleImageError" class="w-full h-full object-contain absolute inset-0 p-2 z-10 bg-black/50 backdrop-blur-sm" />
                                <svg class="w-8 h-8 text-white/20 group-hover:text-cyan-500 transition-colors z-20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                <span class="text-[9px] uppercase font-black tracking-widest text-white/20 group-hover:text-cyan-500 mt-2 z-20">Фото</span>
                                <input ref="fileInput" type="file" accept="image/*" class="hidden" @change="handleImageUpload">
                            </div>

                            <div class="flex-1 space-y-4">
                                <div>
                                    <label class="text-[10px] text-white/30 uppercase font-black tracking-widest mb-1.5 block">Название</label>
                                    <input v-model="form.name" type="text" placeholder="Энергетик Flash 0.5" class="w-full bg-black border border-white/10 focus:border-cyan-500 rounded-xl px-4 py-3 text-white font-bold outline-none transition-all placeholder:text-white/20" />
                                </div>
                                <div>
                                    <label class="text-[10px] text-white/30 uppercase font-black tracking-widest mb-1.5 block">Категория</label>
                                    <select v-model="form.category" class="w-full bg-black border border-white/10 focus:border-cyan-500 rounded-xl px-4 py-3 text-white font-bold outline-none appearance-none">
                                        <option value="Снэки">Снэки</option>
                                        <option value="Напитки">Напитки</option>
                                        <option value="Еда">Еда</option>
                                    </select>
                                </div>
                            </div>
                        </div>

                        <div>
                            <label class="text-[10px] text-white/30 uppercase font-black tracking-widest mb-1.5 block">Цена (РУБ)</label>
                            <input v-model.number="form.price" type="number" class="w-full bg-black border border-white/10 focus:border-cyan-500 rounded-xl px-4 py-4 text-3xl font-black italic text-cyan-500 outline-none transition-all" />
                        </div>
                    </div>

                    <div class="mt-10 flex gap-4">
                        <button @click="closeModal" class="flex-1 py-4 border border-white/10 text-white/40 hover:text-white uppercase text-[10px] font-black tracking-widest rounded-2xl transition-all">
                            Отмена
                        </button>
                        <button @click="saveProduct" :disabled="isProcessing" class="flex-[2] py-4 bg-cyan-500 hover:bg-cyan-400 text-black uppercase text-[12px] font-black italic tracking-widest rounded-2xl transition-all shadow-[0_0_20px_rgba(6,182,212,0.2)] disabled:opacity-50">
                            {{ isProcessing ? 'Запись...' : 'Сохранить' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AdminLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
.animate-in { animation: fade-in 0.3s ease-out forwards; }
@keyframes fade-in { from { opacity: 0; transform: scale(0.98); } to { opacity: 1; transform: scale(1); } }
@keyframes zoom-in { from { opacity: 0; transform: scale(0.95) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
</style>
