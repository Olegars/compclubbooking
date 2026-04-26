<script setup lang="ts">
import { ref, onMounted, computed } from 'vue'
import { usePage, Link, router } from '@inertiajs/vue3'
import axios from 'axios'
import MainLayout from '@/Layouts/MainLayout.vue'

const page = usePage()
const products = ref<any[]>([])
const categories = ['Все', 'Напитки', 'Снэки', 'Еда']
const activeCategory = ref('Все')

// Состояния для модалок
const isProcessing = ref(false)
const confirmData = ref<any>({ show: false, product: null })
const successData = ref({ show: false, message: '' })
const errorData = ref({ show: false, text: '' })

const fetchProducts = async () => {
    try {
        const { data } = await axios.get('/api/shop/products')
        products.value = data
    } catch (e) {
        showError('Не удалось загрузить список товаров')
    }
}

const filteredProducts = computed(() => {
    if (activeCategory.value === 'Все') return products.value
    return products.value.filter(p => p.category === activeCategory.value)
})

// Открыть подтверждение
const askConfirm = (product: any) => {
    if (product.stock <= 0) return; // Жесткая защита от клика по отсутствующему товару
    confirmData.value = { show: true, product }
}

// Выполнить покупку
const executePurchase = async () => {
    const product = confirmData.value.product
    if (!product) return

    confirmData.value.show = false
    isProcessing.value = true

    try {
        const { data } = await axios.post('/api/shop/checkout', { product_id: product.id })

        // Показываем успех
        successData.value = { show: true, message: data.message || 'Заказ оформлен!' }

        // Обновляем баланс в шапке (Inertia reload)
        router.reload({ only: ['gizmo', 'transactions', 'orders'] })

        // После успешной покупки обновляем список товаров, чтобы актуализировать остатки
        fetchProducts()
    } catch (e: any) {
        showError(e.response?.data?.message || 'Ошибка при оформлении заказа')
    } finally {
        isProcessing.value = false
    }
}

const showError = (text: string) => {
    errorData.value = { show: true, text }
}

// Перехватчик битых картинок
const handleImageError = (e: Event) => {
    const target = e.target as HTMLImageElement;
    target.src = '/images/shop/default.png';
}

onMounted(fetchProducts)
</script>

<template>
    <MainLayout>
        <div class="max-w-6xl mx-auto p-6 space-y-8 animate-in zoom-in duration-500">

            <div class="flex flex-col md:flex-row justify-between items-start md:items-center gap-6">
                <div>
                    <h1 class="text-4xl font-black uppercase italic text-[#22c55e] tracking-tighter drop-shadow-[0_0_15px_rgba(34,197,94,0.3)]">Reactor Market</h1>
                    <p class="text-white/30 text-[10px] uppercase tracking-[0.4em] font-black mt-1 italic">Снаряжение и провизия для рейда</p>
                </div>

                <div class="flex flex-wrap gap-2">
                    <button v-for="cat in categories" :key="cat"
                            @click="activeCategory = cat"
                            class="px-5 py-3 rounded-2xl text-[10px] font-black uppercase tracking-widest transition-all border"
                            :class="activeCategory === cat
                                ? 'bg-[#22c55e] text-black border-[#22c55e] shadow-[0_0_15px_rgba(34,197,94,0.3)]'
                                : 'bg-white/5 text-white/40 border-white/5 hover:border-white/20'">
                        {{ cat }}
                    </button>
                </div>
            </div>

            <div v-if="filteredProducts.length > 0" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
                <div v-for="item in filteredProducts" :key="item.id"
                     class="bg-[#0a0a0a] border border-white/5 rounded-[2.5rem] p-6 transition-all relative overflow-hidden flex flex-col shadow-xl"
                     :class="{'opacity-40 grayscale': item.stock <= 0, 'hover:border-[#22c55e]/40 group': item.stock > 0}">

                    <div v-if="item.stock <= 0" class="absolute top-4 left-0 right-0 z-10 flex justify-center">
                        <span class="bg-red-500/90 text-white text-[9px] font-black uppercase tracking-widest px-3 py-1 rounded-full backdrop-blur-sm border border-red-400">
                            Sold Out
                        </span>
                    </div>

                    <div class="aspect-square bg-gradient-to-br from-white/5 to-transparent rounded-[2rem] mb-6 flex items-center justify-center overflow-hidden border border-white/5 relative">
                        <img :src="item.image || '/images/shop/default.png'"
                             @error="handleImageError"
                             class="w-3/4 h-3/4 object-contain transition-transform duration-500"
                             :class="{'group-hover:scale-110': item.stock > 0}" />
                        <div class="absolute inset-0 bg-[#22c55e]/5 opacity-0 transition-opacity" :class="{'group-hover:opacity-100': item.stock > 0}"></div>
                    </div>

                    <div class="text-sm font-black text-white uppercase mb-1 italic tracking-tight">{{ item.name }}</div>
                    <div class="text-[9px] text-[#22c55e]/50 uppercase font-black tracking-widest mb-6">{{ item.category }}</div>

                    <div class="mt-auto flex justify-between items-center">
                        <div class="text-2xl font-black text-white italic tracking-tighter">
                            {{ Math.floor(item.price) }} <span class="text-xs text-[#22c55e] ml-1">₽</span>
                        </div>

                        <button v-if="item.stock > 0" @click="askConfirm(item)"
                                class="w-12 h-12 bg-[#22c55e]/10 border border-[#22c55e]/20 text-[#22c55e] flex items-center justify-center rounded-2xl hover:bg-[#22c55e] hover:text-black transition-all active:scale-90">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 6v12m6-6H6"/></svg>
                        </button>
                        <div v-else class="px-3 py-2 bg-red-500/10 border border-red-500/20 rounded-xl flex items-center justify-center">
                            <span class="text-red-500 text-[10px] font-black uppercase tracking-wider">Пусто</span>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="py-20 text-center border border-dashed border-white/5 rounded-[3rem]">
                <p class="text-white/10 uppercase font-black italic tracking-[0.5em]">Товары в этой категории временно отсутствуют</p>
            </div>
        </div>

        <Teleport to="body">

            <div v-if="confirmData.show" class="fixed inset-0 z-[999999] flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/90 backdrop-blur-md" @click="confirmData.show = false"></div>
                <div class="relative w-full max-w-sm bg-[#050505] border-2 border-[#22c55e]/30 rounded-[3rem] p-10 shadow-[0_0_100px_rgba(34,197,94,0.2)] text-center animate-in zoom-in duration-300">
                    <div class="text-[10px] text-[#22c55e] uppercase font-black tracking-[0.4em] mb-4 italic">Подтверждение заказа</div>
                    <div class="text-2xl font-black text-white uppercase italic leading-tight mb-2">{{ confirmData.product?.name }}</div>
                    <div class="text-4xl font-black text-[#22c55e] italic mb-10">{{ Math.floor(confirmData.product?.price) }} ₽</div>

                    <button @click="executePurchase"
                            class="w-full py-6 bg-[#22c55e] hover:bg-[#2ae06d] text-black font-black uppercase rounded-2xl transition-all shadow-[0_0_20px_rgba(34,197,94,0.3)] active:scale-95 italic">
                        Подтвердить оплату
                    </button>
                    <button @click="confirmData.show = false" class="w-full mt-4 py-4 text-white/20 hover:text-white uppercase text-[10px] font-black tracking-widest transition-all">
                        Отмена
                    </button>
                </div>
            </div>

            <div v-if="successData.show" class="fixed inset-0 z-[999999] flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-xl"></div>
                <div class="relative w-full max-w-sm bg-[#050505] border-2 border-[#22c55e] rounded-[3rem] p-10 text-center animate-in zoom-in duration-500">
                    <div class="w-20 h-20 bg-[#22c55e]/10 border-2 border-[#22c55e] rounded-full flex items-center justify-center mx-auto mb-6 shadow-[0_0_30px_rgba(34,197,94,0.2)]">
                        <svg class="w-10 h-10 text-[#22c55e]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <h2 class="text-2xl font-black text-white uppercase italic mb-2">Заказ Принят</h2>
                    <p class="text-white/50 text-sm mb-8 italic">{{ successData.message }}</p>
                    <button @click="successData.show = false" class="w-full py-5 bg-white/5 border border-white/10 text-white font-black uppercase rounded-2xl hover:bg-white/10 transition-all italic">
                        Отлично
                    </button>
                </div>
            </div>

            <div v-if="errorData.show" class="fixed inset-0 z-[999999] flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/90 backdrop-blur-md" @click="errorData.show = false"></div>
                <div class="relative w-full max-w-sm bg-[#050505] border-2 border-red-500/30 rounded-[3rem] p-10 text-center shadow-[0_0_100px_rgba(239,68,68,0.2)]">
                    <h2 class="text-red-500 text-2xl font-black uppercase italic mb-4 tracking-tighter">Ошибка Транзакции</h2>
                    <p class="text-white/70 text-sm font-mono mb-8 italic leading-relaxed">{{ errorData.text }}</p>
                    <button @click="errorData.show = false" class="w-full py-5 bg-red-500/20 hover:bg-red-500 border border-red-500/50 hover:text-black rounded-2xl text-red-500 font-black uppercase transition-all italic tracking-widest">ПОНЯТНО</button>
                </div>
            </div>

            <div v-if="isProcessing" class="fixed inset-0 z-[9999999] bg-black/60 flex items-center justify-center">
                <div class="w-12 h-12 border-4 border-[#22c55e]/20 border-t-[#22c55e] rounded-full animate-spin"></div>
            </div>

        </Teleport>
    </MainLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
.animate-in { animation: zoom-in 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
@keyframes zoom-in {
    from { opacity: 0; transform: scale(0.95); }
    to { opacity: 1; transform: scale(1); }
}
</style>
