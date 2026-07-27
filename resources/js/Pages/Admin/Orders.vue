<script setup>
import { router } from '@inertiajs/vue3'
import { watch } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue' // <-- ИМПОРТИРУЕМ ОБОЛОЧКУ АДМИНКИ

const props = defineProps({ orders: Array })

const setStatus = (id, status) => {
    router.post(`/admin/orders/${id}/status`, { status }, {
        preserveScroll: true
    })
}

watch(() => props.orders?.length, (newCount, oldCount) => {
    if (newCount > oldCount) {
        // Если заказов стало больше — играем звук
        const audio = new Audio('/sounds/notification.mp3');
        audio.play().catch(e => console.log('Аудио заблокировано браузером до первого клика'));
    }
})
</script>

<template>
    <AdminLayout>
        <div class="max-w-5xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono text-white pb-10">

            <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[2.5rem] shadow-xl flex items-center gap-4">
                <div class="w-3 h-10 bg-[#22c55e] rounded-full animate-pulse shadow-[0_0_15px_rgba(34,197,94,0.4)]"></div>
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-white tracking-tighter">Очередь заказов</h1>
                    <p class="text-white/30 text-[10px] uppercase tracking-[0.4em] font-black mt-1 italic">Reactor Market // Обработка</p>
                </div>
            </div>

            <div v-if="orders && orders.length > 0" class="space-y-4">
                <div v-for="order in orders" :key="order.id"
                     class="bg-[#050505] border border-white/5 rounded-[2rem] p-6 flex flex-col md:flex-row items-start md:items-center justify-between group hover:border-[#22c55e]/40 transition-all shadow-lg relative overflow-hidden">

                    <div class="absolute left-0 top-0 bottom-0 w-1 bg-[#22c55e]/20 group-hover:bg-[#22c55e] transition-colors"></div>

                    <div class="pl-4 mb-4 md:mb-0">
                        <div class="text-[10px] text-[#22c55e] font-black uppercase tracking-widest mb-2 italic">
                            Клиент: <span class="text-white">{{ order.user?.name }}</span> ({{ order.user?.phone }})
                        </div>
                        <div class="text-2xl font-black uppercase italic tracking-tight text-white mb-1">
                            {{ order.product_name }}
                        </div>
                        <div class="flex flex-wrap items-center gap-2 mt-2">
                            <div class="inline-flex items-center gap-2 px-3 py-1 bg-white/5 border border-white/10 rounded-lg">
                                <span class="text-[9px] text-white/40 uppercase font-black tracking-widest">Доставить на:</span>
                                <span class="text-sm text-white font-black">{{ order.pc_name }}</span>
                            </div>
                            <div class="inline-flex items-center px-3 py-1 rounded-lg border text-[9px] font-black uppercase tracking-widest"
                                 :class="order.status === 'cooking'
                                    ? 'bg-yellow-500/10 border-yellow-500/30 text-yellow-400'
                                    : 'bg-[#22c55e]/10 border-[#22c55e]/30 text-[#22c55e]'">
                                {{ order.status_label || order.status }}
                            </div>
                        </div>
                    </div>

                    <div class="flex gap-3 pl-4 md:pl-0 w-full md:w-auto">
                        <button v-if="order.status === 'pending'"
                                @click="setStatus(order.id, 'cooking')"
                                class="flex-1 md:flex-none px-8 py-4 bg-yellow-500 hover:bg-yellow-400 text-black font-black uppercase text-xs tracking-widest rounded-xl transition-all active:scale-95">
                            В работу
                        </button>
                        <button v-if="order.status === 'cooking' || order.status === 'pending'"
                                @click="setStatus(order.id, 'delivered')"
                                class="flex-1 md:flex-none px-8 py-4 bg-[#22c55e] hover:bg-[#1ea34d] text-black font-black uppercase text-xs tracking-widest rounded-xl transition-all shadow-[0_0_20px_rgba(34,197,94,0.2)] active:scale-95">
                            Выполнен
                        </button>
                        <button @click="setStatus(order.id, 'cancelled')"
                                class="flex-1 md:flex-none px-6 py-4 bg-red-500/10 border border-red-500/30 text-red-500 font-black uppercase text-xs tracking-widest rounded-xl hover:bg-red-500 hover:text-black transition-all active:scale-95">
                            Отмена
                        </button>
                    </div>
                </div>
            </div>

            <div v-else class="text-center py-32 border-2 border-dashed border-white/5 rounded-[3rem] bg-[#050505]">
                <div class="text-white/10 uppercase font-black italic tracking-[0.5em] text-xl">Очередь пуста</div>
                <div class="text-[10px] text-white/20 uppercase tracking-widest mt-2 font-bold">Ожидание новых поступлений...</div>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
.animate-in { animation: fade-in 0.4s ease-out forwards; }
@keyframes fade-in { from { opacity: 0; transform: translateY(10px); } to { opacity: 1; transform: translateY(0); } }
</style>
