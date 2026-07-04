<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

// Получаем заявки из контроллера
const props = defineProps<{
    claims: any[]
}>()

// Метод обновления статуса (Одобрить/Отклонить)
const updateStatus = (id: number, status: 'approved' | 'rejected') => {
    const actionName = status === 'approved' ? 'подтвердить начисление бонуса' : 'отклонить заявку';
    if (confirm(`Вы уверены, что хотите ${actionName}?`)) {
        router.post(`/admin/api/bonuses/verify/${id}`, { status }, {
            preserveScroll: true,
            onSuccess: () => {
                // Можно добавить тост-уведомление об успехе
            }
        })
    }
}
</script>

<template>
    <Head title="REACTOR | Верификация" />
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">

            <div class="flex flex-col md:flex-row justify-between items-start md:items-center bg-[#0a0a0a] border border-white/5 p-8 rounded-[2.5rem] shadow-2xl gap-6">
                <div>
                    <h1 class="text-3xl font-black uppercase italic text-yellow-500 tracking-tighter">Reputation <span class="text-white">Control</span></h1>
                    <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">Верификация отзывов с Яндекс.Карт</p>
                </div>
                <div class="flex gap-4 w-full md:w-auto">
                    <div class="flex-1 md:flex-none px-6 py-4 bg-white/5 rounded-2xl border border-white/5 text-center md:text-right">
                        <div class="text-[10px] text-white/20 uppercase font-black tracking-widest">Ожидают проверки</div>
                        <div class="text-2xl font-black text-yellow-500">{{ claims.filter(c => c.status === 'pending').length }}</div>
                    </div>
                </div>
            </div>

            <div class="grid gap-4">
                <div v-for="claim in claims" :key="claim.id"
                     class="bg-[#050505] border border-white/5 rounded-[2rem] p-6 flex flex-col md:flex-row gap-8 items-start transition-all hover:border-white/10"
                     :class="{'opacity-50 grayscale': claim.status !== 'pending'}">

                    <div class="w-full md:w-64 shrink-0">
                        <div class="text-[10px] text-white/20 uppercase font-black mb-2 italic tracking-widest">Пользователь</div>
                        <div class="text-white font-black uppercase italic tracking-tight text-lg">{{ claim.user.name }}</div>
                        <div class="text-white/40 text-xs font-mono mt-1">{{ claim.user.phone }}</div>
                        <div class="mt-4 inline-block px-3 py-1 bg-yellow-500/10 rounded-lg text-[10px] font-black text-yellow-500 italic border border-yellow-500/20 uppercase tracking-widest">
                            Награда: +{{ Math.floor(claim.bonus_amount) }}₽
                        </div>
                    </div>

                    <div class="flex-1 w-full bg-black/40 rounded-2xl p-5 border border-white/5 relative overflow-hidden group">
                        <div class="text-[10px] text-white/20 uppercase font-black mb-3 italic tracking-widest">Прямая ссылка (URL):</div>

                        <a :href="claim.review_link" target="_blank"
                           class="inline-flex items-center gap-3 text-sm text-cyan-500 hover:text-cyan-400 leading-relaxed font-mono break-all transition-colors p-4 bg-cyan-500/5 rounded-xl border border-cyan-500/10 hover:border-cyan-500/30 w-full">
                            <svg class="w-5 h-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            {{ claim.review_link }}
                        </a>

                        <div v-if="claim.status === 'pending'" class="mt-4 flex items-center gap-2 text-[10px] text-yellow-500 uppercase font-black italic tracking-widest bg-yellow-500/5 p-3 rounded-xl border border-yellow-500/10">
                            <svg class="w-4 h-4 shrink-0" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                            Проверьте наличие 5 звезд и свежую дату!
                        </div>

                        <div v-if="claim.status === 'approved'" class="absolute top-4 right-4 text-[#22c55e] font-black text-xs tracking-widest uppercase italic bg-[#22c55e]/10 px-3 py-1 rounded-lg border border-[#22c55e]/20">ОДОБРЕНО</div>
                        <div v-if="claim.status === 'rejected'" class="absolute top-4 right-4 text-red-500 font-black text-xs tracking-widest uppercase italic bg-red-500/10 px-3 py-1 rounded-lg border border-red-500/20 opacity-70">ОТКЛОНЕНО</div>
                    </div>

                    <div v-if="claim.status === 'pending'" class="w-full md:w-auto flex flex-row md:flex-col gap-2 shrink-0">
                        <button @click="updateStatus(claim.id, 'approved')"
                                class="flex-1 px-8 py-5 bg-[#22c55e] text-black hover:bg-[#2ae06d] rounded-2xl text-[10px] font-black uppercase tracking-[0.2em] transition-all italic shadow-[0_0_20px_rgba(34,197,94,0.2)] active:scale-95">
                            Зачислить
                        </button>
                        <button @click="updateStatus(claim.id, 'rejected')"
                                class="flex-1 px-8 py-5 bg-white/5 border border-red-500/30 text-red-500 hover:bg-red-500/10 rounded-2xl text-[10px] font-black uppercase tracking-[0.2em] transition-all italic active:scale-95">
                            Отказ
                        </button>
                    </div>
                </div>

                <div v-if="claims.length === 0" class="py-32 text-center border border-dashed border-white/5 rounded-[3rem] bg-black/50">
                    <div class="w-16 h-16 bg-white/5 rounded-full flex items-center justify-center mx-auto mb-6">
                        <svg class="w-8 h-8 text-white/20" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    </div>
                    <p class="text-white/20 uppercase font-black italic tracking-[0.5em] text-sm">Очередь верификации пуста</p>
                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
.animate-in { animation: fade-in 0.4s ease-out forwards; }
@keyframes fade-in {
    from { opacity: 0; transform: translateY(10px); }
    to { opacity: 1; transform: translateY(0); }
}
</style>
