<script setup lang="ts">
import { ref, onMounted, computed, watch } from 'vue'
import { router } from '@inertiajs/vue3'
import axios from 'axios'
import MainLayout from '@/Layouts/MainLayout.vue'
import { useClubName } from '@/Composables/useClubName'

const clubName = useClubName()

type Product = {
    id: number
    name: string
    price: number
    category: string
    image?: string | null
    stock: number
}

type CartLine = {
    product_id: number
    name: string
    unit_price: number
    qty: number
    image?: string | null
    stock: number
}

const CART_STORAGE_KEY = 'reactor_shop_cart_v1'

const products = ref<Product[]>([])
const categories = ['Все', 'Напитки', 'Снэки', 'Еда']
const activeCategory = ref('Все')

const loadCartFromStorage = (): CartLine[] => {
    try {
        const raw = localStorage.getItem(CART_STORAGE_KEY)
        if (!raw) return []
        const parsed = JSON.parse(raw)
        if (!Array.isArray(parsed)) return []
        return parsed
            .filter((row: any) => row && Number(row.product_id) > 0 && Number(row.qty) > 0)
            .map((row: any) => ({
                product_id: Number(row.product_id),
                name: String(row.name || ''),
                unit_price: Number(row.unit_price) || 0,
                qty: Math.max(1, Math.floor(Number(row.qty) || 1)),
                image: row.image ?? null,
                stock: Math.max(0, Number(row.stock) || 0),
            }))
    } catch {
        return []
    }
}

const cart = ref<CartLine[]>(loadCartFromStorage())
const cartOpen = ref(false)
const isProcessing = ref(false)
const confirmCheckout = ref(false)
const successData = ref({ show: false, message: '' })
const errorData = ref({ show: false, text: '' })

const persistCart = () => {
    try {
        localStorage.setItem(CART_STORAGE_KEY, JSON.stringify(cart.value))
    } catch {
        // ignore quota / private mode
    }
}

watch(cart, persistCart, { deep: true })

const fetchProducts = async () => {
    try {
        const { data } = await axios.get('/api/shop/products')
        products.value = Array.isArray(data) ? data : (data.products || [])
        syncCartWithStock()
    } catch (e) {
        showError('Не удалось загрузить список товаров')
    }
}

/** Подтянуть цены/остатки и выкинуть снятые с витрины позиции */
const syncCartWithStock = () => {
    if (!cart.value.length || !products.value.length) return
    const byId = new Map(products.value.map(p => [Number(p.id), p]))
    const next: CartLine[] = []
    for (const line of cart.value) {
        const product = byId.get(line.product_id)
        if (!product || product.stock <= 0) continue
        next.push({
            ...line,
            name: product.name,
            unit_price: Number(product.price),
            image: product.image,
            stock: Number(product.stock),
            qty: Math.min(line.qty, Number(product.stock)),
        })
    }
    cart.value = next
}

const filteredProducts = computed(() => {
    if (activeCategory.value === 'Все') return products.value
    return products.value.filter(p => p.category === activeCategory.value)
})

const cartCount = computed(() => cart.value.reduce((s, l) => s + l.qty, 0))
const cartTotal = computed(() => cart.value.reduce((s, l) => s + l.unit_price * l.qty, 0))

const qtyInCart = (productId: number) =>
    cart.value.find(l => l.product_id === productId)?.qty || 0

const addToCart = (product: Product) => {
    if (product.stock <= 0) return
    const existing = cart.value.find(l => l.product_id === product.id)
    if (existing) {
        if (existing.qty >= product.stock) {
            showError(`На складе только ${product.stock} шт.`)
            return
        }
        existing.qty += 1
        existing.stock = product.stock
    } else {
        cart.value.push({
            product_id: product.id,
            name: product.name,
            unit_price: Number(product.price),
            qty: 1,
            image: product.image,
            stock: product.stock,
        })
    }
    cartOpen.value = true
}

const setQty = (productId: number, qty: number) => {
    const line = cart.value.find(l => l.product_id === productId)
    if (!line) return
    const next = Math.floor(qty)
    if (next <= 0) {
        cart.value = cart.value.filter(l => l.product_id !== productId)
        return
    }
    if (next > line.stock) {
        showError(`На складе только ${line.stock} шт.`)
        line.qty = line.stock
        return
    }
    line.qty = next
}

const clearCart = () => {
    cart.value = []
    cartOpen.value = false
}

const askCheckout = () => {
    if (!cart.value.length) return
    confirmCheckout.value = true
}

const executeCheckout = async () => {
    if (!cart.value.length) return
    confirmCheckout.value = false
    isProcessing.value = true

    try {
        const { data } = await axios.post('/api/shop/checkout', {
            items: cart.value.map(l => ({
                product_id: l.product_id,
                qty: l.qty,
            })),
            order_type: 'desktop',
            payment_method: 'account',
        })

        successData.value = { show: true, message: data.message || 'Заказ оформлен!' }
        cart.value = []
        persistCart()
        cartOpen.value = false
        window.dispatchEvent(new Event('shop-order-placed'))
        router.reload({ only: ['auth', 'transactions', 'orders'] })
        fetchProducts()
    } catch (e: any) {
        const errors = e.response?.data?.errors
        const firstError = errors && typeof errors === 'object'
            ? (Object.values(errors).flat()[0] as string)
            : null
        showError(firstError || e.response?.data?.message || 'Ошибка при оформлении заказа')
    } finally {
        isProcessing.value = false
    }
}

const showError = (text: string) => {
    errorData.value = { show: true, text }
}

const handleImageError = (e: Event) => {
    const target = e.target as HTMLImageElement
    target.src = '/images/shop/default.png'
}

onMounted(fetchProducts)
</script>

<template>
    <MainLayout>
        <div class="max-w-6xl mx-auto px-0 py-3 sm:p-6 space-y-4 sm:space-y-8 animate-in zoom-in duration-500 pb-28">

            <div class="space-y-5">
                <div>
                    <h1 class="text-4xl font-black uppercase italic text-[#22c55e] tracking-tighter drop-shadow-[0_0_15px_rgba(34,197,94,0.3)]">{{ clubName }} Market</h1>
                    <p class="mt-2 text-sm sm:text-base text-white/70 font-medium normal-case tracking-normal not-italic leading-snug">
                        Снаряжение и провизия для рейда
                    </p>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <button v-for="cat in categories" :key="cat"
                            type="button"
                            @click="activeCategory = cat"
                            class="shop-nav-btn"
                            :class="{ 'active': activeCategory === cat }">
                        {{ cat }}
                    </button>
                    <button type="button" @click="cartOpen = true" class="shop-nav-btn shop-cart-btn relative">
                        Корзина
                        <span v-if="cartCount > 0"
                              class="absolute -top-2 -right-2 min-w-[22px] h-[22px] px-1 rounded-full bg-[#22c55e] text-black text-[10px] font-black flex items-center justify-center not-italic">
                            {{ cartCount }}
                        </span>
                    </button>
                </div>
            </div>

            <div v-if="filteredProducts.length > 0" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-1.5 sm:gap-6">
                <div v-for="item in filteredProducts" :key="item.id"
                     class="bg-white/5 md:bg-[#0a0a0a] border border-white/5 rounded-xl sm:rounded-[1rem] p-4 sm:p-6 transition-all relative overflow-hidden flex flex-col shadow-xl"
                     :class="{'opacity-40 grayscale': item.stock <= 0, 'hover:border-[#22c55e]/40 group': item.stock > 0}">

                    <div v-if="item.stock <= 0" class="absolute top-4 left-0 right-0 z-10 flex justify-center">
                        <span class="bg-red-500/90 text-white text-[9px] font-black uppercase tracking-widest px-3 py-1 rounded-full backdrop-blur-sm border border-red-400">
                            Sold Out
                        </span>
                    </div>

                    <div class="aspect-square bg-gradient-to-br from-white/5 to-transparent rounded-[0.875rem] mb-6 flex items-center justify-center overflow-hidden border border-white/5 relative">
                        <img :src="item.image || '/images/shop/default.png'"
                             @error="handleImageError"
                             class="w-3/4 h-3/4 object-contain transition-transform duration-500"
                             :class="{'group-hover:scale-110': item.stock > 0}" />
                    </div>

                    <div class="text-sm font-black text-white uppercase mb-1 italic tracking-tight">{{ item.name }}</div>
                    <div class="text-[9px] text-[#22c55e]/50 uppercase font-black tracking-widest mb-6">{{ item.category }}</div>

                    <div class="mt-auto flex justify-between items-center gap-3">
                        <div class="text-2xl font-black text-white italic tracking-tighter">
                            {{ Math.floor(item.price) }} <span class="text-xs text-[#22c55e] ml-1">₽</span>
                        </div>

                        <button v-if="item.stock > 0" type="button" @click="addToCart(item)"
                                class="relative w-12 h-12 bg-[#22c55e]/10 border border-[#22c55e]/20 text-[#22c55e] flex items-center justify-center rounded-2xl hover:bg-[#22c55e] hover:text-black transition-all active:scale-90 cursor-pointer">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 6v12m6-6H6"/></svg>
                            <span v-if="qtyInCart(item.id)"
                                  class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-[#22c55e] text-black text-[9px] font-black flex items-center justify-center">
                                {{ qtyInCart(item.id) }}
                            </span>
                        </button>
                        <div v-else class="px-3 py-2 bg-red-500/10 border border-red-500/20 rounded-xl flex items-center justify-center">
                            <span class="text-red-500 text-[10px] font-black uppercase tracking-wider">Пусто</span>
                        </div>
                    </div>
                </div>
            </div>

            <div v-else class="py-20 text-center border border-dashed border-white/5 rounded-[1.125rem]">
                <p class="text-white/10 uppercase font-black italic tracking-[0.5em]">Товары в этой категории временно отсутствуют</p>
            </div>
        </div>

        <!-- Floating cart bar -->
        <div v-if="cartCount > 0 && !cartOpen"
             class="fixed bottom-3 left-[3px] right-[3px] sm:left-1/2 sm:right-auto sm:-translate-x-1/2 z-[100] sm:w-[calc(100%-2rem)] sm:max-w-md">
            <button type="button" @click="cartOpen = true"
                    class="w-full flex items-center justify-between gap-4 px-6 py-4 rounded-2xl bg-[#22c55e] text-black font-black uppercase tracking-widest shadow-[0_0_40px_rgba(34,197,94,0.45)] cursor-pointer">
                <span class="text-xs">Корзина · {{ cartCount }} шт</span>
                <span class="text-sm italic">{{ Math.floor(cartTotal) }} ₽</span>
            </button>
        </div>

        <Teleport to="body">
            <!-- Cart drawer -->
            <div v-if="cartOpen" class="fixed inset-0 z-[999999] flex justify-end">
                <div class="absolute inset-0 bg-black/80 backdrop-blur-md" @click="cartOpen = false"></div>
                <div class="relative w-full max-w-md h-full bg-[#050505] border-l border-white/10 flex flex-col shadow-2xl animate-in zoom-in duration-300">
                    <div class="p-6 border-b border-white/5 flex items-center justify-between gap-4">
                        <div>
                            <div class="text-[10px] text-[#22c55e] uppercase font-black tracking-[0.4em] italic">Корзина</div>
                            <div class="text-xl font-black text-white uppercase italic">{{ cartCount }} поз.</div>
                        </div>
                        <button type="button" @click="cartOpen = false"
                                class="px-4 py-2 text-[10px] font-black uppercase tracking-widest text-white/40 hover:text-white cursor-pointer">
                            Закрыть
                        </button>
                    </div>

                    <div class="flex-1 overflow-y-auto p-6 space-y-4">
                        <div v-if="!cart.length" class="py-16 text-center text-white/20 text-xs font-black uppercase tracking-widest">
                            Корзина пуста
                        </div>
                        <div v-for="line in cart" :key="line.product_id"
                             class="flex gap-4 p-4 rounded-2xl border border-white/5 bg-white/[0.02]">
                            <div class="w-16 h-16 rounded-xl bg-white/5 border border-white/5 flex items-center justify-center shrink-0 overflow-hidden">
                                <img :src="line.image || '/images/shop/default.png'" @error="handleImageError" class="w-3/4 h-3/4 object-contain" />
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="text-sm font-black text-white uppercase italic truncate">{{ line.name }}</div>
                                <div class="text-[10px] text-[#22c55e] font-black mt-1">{{ Math.floor(line.unit_price) }} ₽</div>
                                <div class="mt-3 flex items-center gap-2">
                                    <button type="button" @click="setQty(line.product_id, line.qty - 1)"
                                            class="w-8 h-8 rounded-lg bg-white/5 border border-white/10 text-white font-black cursor-pointer hover:bg-white/10">−</button>
                                    <span class="w-8 text-center text-sm font-black text-white">{{ line.qty }}</span>
                                    <button type="button" @click="setQty(line.product_id, line.qty + 1)"
                                            class="w-8 h-8 rounded-lg bg-white/5 border border-white/10 text-white font-black cursor-pointer hover:bg-white/10">+</button>
                                    <button type="button" @click="setQty(line.product_id, 0)"
                                            class="ml-auto text-[9px] font-black uppercase tracking-widest text-red-400/70 hover:text-red-400 cursor-pointer">
                                        Убрать
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="p-6 border-t border-white/5 space-y-3">
                        <div class="flex justify-between items-end">
                            <span class="text-[10px] text-white/30 uppercase font-black tracking-widest">Итого</span>
                            <span class="text-3xl font-black text-[#22c55e] italic tracking-tighter">{{ Math.floor(cartTotal) }} ₽</span>
                        </div>
                        <button type="button" :disabled="!cart.length" @click="askCheckout"
                                class="w-full py-5 bg-[#22c55e] hover:bg-[#2ae06d] disabled:opacity-40 text-black font-black uppercase rounded-2xl transition-all shadow-[0_0_20px_rgba(34,197,94,0.3)] active:scale-95 italic cursor-pointer">
                            Оформить заказ
                        </button>
                        <button v-if="cart.length" type="button" @click="clearCart"
                                class="w-full py-3 text-white/20 hover:text-white uppercase text-[10px] font-black tracking-widest transition-all cursor-pointer">
                            Очистить корзину
                        </button>
                    </div>
                </div>
            </div>

            <div v-if="confirmCheckout" class="fixed inset-0 z-[999999] flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/90 backdrop-blur-md" @click="confirmCheckout = false"></div>
                <div class="relative w-full max-w-sm bg-[#050505] border-2 border-[#22c55e]/30 rounded-[1.125rem] p-10 shadow-[0_0_100px_rgba(34,197,94,0.2)] text-center animate-in zoom-in duration-300">
                    <div class="text-[10px] text-[#22c55e] uppercase font-black tracking-[0.4em] mb-4 italic">Подтверждение заказа</div>
                    <div class="text-2xl font-black text-white uppercase italic leading-tight mb-2">{{ cartCount }} поз. · {{ Math.floor(cartTotal) }} ₽</div>
                    <ul class="text-left space-y-1 mb-8 max-h-40 overflow-y-auto">
                        <li v-for="line in cart" :key="line.product_id" class="text-[11px] text-white/50 font-bold uppercase tracking-wide flex justify-between gap-2">
                            <span class="truncate">{{ line.name }} ×{{ line.qty }}</span>
                            <span class="text-[#22c55e] shrink-0">{{ Math.floor(line.unit_price * line.qty) }} ₽</span>
                        </li>
                    </ul>
                    <button type="button" @click="executeCheckout"
                            class="w-full py-6 bg-[#22c55e] hover:bg-[#2ae06d] text-black font-black uppercase rounded-2xl transition-all shadow-[0_0_20px_rgba(34,197,94,0.3)] active:scale-95 italic cursor-pointer">
                        Подтвердить оплату
                    </button>
                    <button type="button" @click="confirmCheckout = false" class="w-full mt-4 py-4 text-white/20 hover:text-white uppercase text-[10px] font-black tracking-widest transition-all cursor-pointer">
                        Отмена
                    </button>
                </div>
            </div>

            <div v-if="successData.show" class="fixed inset-0 z-[999999] flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/95 backdrop-blur-xl"></div>
                <div class="relative w-full max-w-sm bg-[#050505] border-2 border-[#22c55e] rounded-[1.125rem] p-10 text-center animate-in zoom-in duration-500">
                    <div class="w-20 h-20 bg-[#22c55e]/10 border-2 border-[#22c55e] rounded-full flex items-center justify-center mx-auto mb-6 shadow-[0_0_30px_rgba(34,197,94,0.2)]">
                        <svg class="w-10 h-10 text-[#22c55e]" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                    </div>
                    <h2 class="text-2xl font-black text-white uppercase italic mb-2">Заказ Принят</h2>
                    <p class="text-white/50 text-sm mb-8 italic">{{ successData.message }}</p>
                    <button type="button" @click="successData.show = false" class="w-full py-5 bg-white/5 border border-white/10 text-white font-black uppercase rounded-2xl hover:bg-white/10 transition-all italic cursor-pointer">
                        Отлично
                    </button>
                </div>
            </div>

            <div v-if="errorData.show" class="fixed inset-0 z-[999999] flex items-center justify-center p-4">
                <div class="absolute inset-0 bg-black/90 backdrop-blur-md" @click="errorData.show = false"></div>
                <div class="relative w-full max-w-sm bg-[#050505] border-2 border-red-500/30 rounded-[1.125rem] p-10 text-center shadow-[0_0_100px_rgba(239,68,68,0.2)]">
                    <h2 class="text-red-500 text-2xl font-black uppercase italic mb-4 tracking-tighter">Ошибка Транзакции</h2>
                    <p class="text-white/70 text-sm font-mono mb-8 italic leading-relaxed">{{ errorData.text }}</p>
                    <button type="button" @click="errorData.show = false" class="w-full py-5 bg-red-500/20 hover:bg-red-500 border border-red-500/50 hover:text-black rounded-2xl text-red-500 font-black uppercase transition-all italic tracking-widest cursor-pointer">ПОНЯТНО</button>
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

.shop-nav-btn {
    @apply px-4 py-2.5 sm:px-6 sm:py-3 border border-white/10 rounded-xl text-[10px] sm:text-[11px] font-black
           transition-all cursor-pointer uppercase tracking-widest italic text-white/70 hover:border-white/25 hover:text-white;
    font-family: Arial, Helvetica, sans-serif;
}
.shop-nav-btn.active {
    @apply bg-[#22c55e] text-black border-transparent shadow-[0_0_20px_rgba(34,197,94,0.4)];
}
.shop-cart-btn {
    @apply text-[#22c55e] border-[#22c55e]/30;
}
.shop-cart-btn:hover {
    @apply bg-[#22c55e] text-black border-transparent;
}
</style>
