<script setup lang="ts">
import { ref } from 'vue'
import { useForm, router } from '@inertiajs/vue3'
import AdminLayout from '@/Layouts/AdminLayout.vue'

const props = defineProps<{
    promocodes: any[]
}>()

const form = useForm({
    code: '',
    type: 'bonus_money',
    value: 500,
    max_uses: 1
})

// Генератор случайного кода в стиле киберпанк (напр: RCT-X8F9-V)
const generateRandomCode = () => {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'
    let result = 'RCT-'
    for (let i = 0; i < 4; i++) result += chars.charAt(Math.floor(Math.random() * chars.length))
    result += '-'
    for (let i = 0; i < 2; i++) result += chars.charAt(Math.floor(Math.random() * chars.length))
    form.code = result
}

const submit = () => {
    form.post('/admin/promocodes', {
        preserveScroll: true,
        onSuccess: () => {
            form.reset()
            form.code = '' // Очищаем вручную
        }
    })
}

const deleteCode = (id: number) => {
    if (confirm('ВНИМАНИЕ: Точно удалить этот промокод? Игроки больше не смогут его активировать.')) {
        router.delete(`/admin/promocodes/${id}`, { preserveScroll: true })
    }
}

// Хелперы для визуала
const getProgress = (used: number, max: number) => Math.min((used / max) * 100, 100)
const isDepleted = (used: number, max: number) => used >= max
</script>

<template>
    <AdminLayout>
        <div class="p-8 h-full flex flex-col font-mono text-white animate-in fade-in duration-500">

            <div class="flex justify-between items-end mb-8">
                <div>
                    <div class="text-[10px] text-purple-500 font-black uppercase mb-1 tracking-[0.3em] flex items-center gap-2">
                        <span class="w-1.5 h-1.5 bg-purple-500 rounded-full animate-pulse"></span>
                        Marketing Engine
                    </div>
                    <h1 class="text-4xl font-black italic tracking-tighter uppercase text-white">Генератор кодов</h1>
                </div>
            </div>

            <div class="grid grid-cols-1 xl:grid-cols-12 gap-8 items-start">

                <div class="xl:col-span-4 bg-[#0a0a0a] border border-purple-500/30 rounded-[2.5rem] p-8 shadow-[0_0_50px_rgba(168,85,247,0.05)] sticky top-8">
                    <h2 class="text-xl font-black uppercase italic text-purple-400 mb-6 border-b border-purple-500/20 pb-4">Создать акцию</h2>

                    <form @submit.prevent="submit" class="space-y-6">

                        <div>
                            <label class="text-[10px] uppercase text-white/40 font-black tracking-widest mb-2 flex justify-between">
                                <span>Текст промокода</span>
                                <button type="button" @click="generateRandomCode" class="text-purple-400 hover:text-purple-300 transition-colors">Сгенерировать случайный</button>
                            </label>
                            <input v-model="form.code" type="text" placeholder="SUMMER2026" maxlength="20"
                                   class="w-full bg-black border border-white/10 rounded-2xl p-4 text-white font-black text-xl uppercase tracking-widest focus:border-purple-500 outline-none transition-colors shadow-inner placeholder:text-white/10" required />
                            <div v-if="form.errors.code" class="text-red-500 text-[10px] mt-1 font-bold uppercase">{{ form.errors.code }}</div>
                        </div>

                        <div class="grid grid-cols-2 gap-4">
                            <div class="col-span-2">
                                <label class="text-[10px] uppercase text-white/40 font-black tracking-widest mb-2 block">Тип награды</label>
                                <div class="flex gap-2">
                                    <button type="button" @click="form.type = 'bonus_money'"
                                            class="flex-1 py-3 border rounded-xl font-black uppercase text-[10px] tracking-widest transition-all"
                                            :class="form.type === 'bonus_money' ? 'bg-purple-500/20 border-purple-500 text-purple-400' : 'bg-black border-white/10 text-white/30 hover:border-white/30'">
                                        Баланс (Фантики)
                                    </button>
                                    <button type="button" disabled title="В разработке"
                                            class="flex-1 py-3 border rounded-xl font-black uppercase text-[10px] tracking-widest transition-all bg-black border-white/5 text-white/10 cursor-not-allowed">
                                        Скидка (%)
                                    </button>
                                </div>
                            </div>

                            <div>
                                <label class="text-[10px] uppercase text-white/40 font-black tracking-widest mb-2 block">Сумма (₽)</label>
                                <input v-model="form.value" type="number" min="1"
                                       class="w-full bg-black border border-white/10 rounded-xl p-4 text-white font-bold focus:border-purple-500 outline-none transition-colors" required />
                            </div>

                            <div>
                                <label class="text-[10px] uppercase text-white/40 font-black tracking-widest mb-2 block">Лимит активаций</label>
                                <input v-model="form.max_uses" type="number" min="1"
                                       class="w-full bg-black border border-white/10 rounded-xl p-4 text-white font-bold focus:border-purple-500 outline-none transition-colors" required />
                            </div>
                        </div>

                        <button type="submit" :disabled="form.processing"
                                class="w-full py-5 bg-purple-600 hover:bg-purple-500 text-white font-black uppercase rounded-2xl tracking-[0.2em] transition-all shadow-[0_0_20px_rgba(147,51,234,0.3)] active:scale-95 disabled:opacity-50">
                            {{ form.processing ? 'ЗАПИСЬ...' : 'АКТИВИРОВАТЬ КОД' }}
                        </button>
                    </form>
                </div>

                <div class="xl:col-span-8 grid grid-cols-1 md:grid-cols-2 gap-4">

                    <div v-if="promocodes.length === 0" class="col-span-full py-20 border-2 border-dashed border-white/5 rounded-[3rem] text-center bg-[#050505]">
                        <div class="text-5xl mb-4 opacity-50">🎁</div>
                        <div class="text-white/20 text-[10px] uppercase font-black tracking-[0.4em] italic">Активных кампаний нет</div>
                    </div>

                    <div v-for="promo in promocodes" :key="promo.id"
                         class="bg-[#050505] border rounded-[2rem] p-6 relative overflow-hidden transition-all group flex flex-col justify-between min-h-[220px]"
                         :class="isDepleted(promo.used_count, promo.max_uses) ? 'border-red-500/20 opacity-60 grayscale' : 'border-white/10 hover:border-purple-500/50 hover:bg-purple-500/[0.02]'">

                        <div class="flex justify-between items-start z-10">
                            <div>
                                <div class="text-xs uppercase font-black mb-1 tracking-widest flex items-center gap-2"
                                     :class="isDepleted(promo.used_count, promo.max_uses) ? 'text-red-500' : 'text-purple-400'">
                                    {{ promo.type === 'bonus_money' ? 'Пополнение баланса' : 'Скидка' }}
                                    <span v-if="isDepleted(promo.used_count, promo.max_uses)" class="text-[8px] bg-red-500/20 px-2 py-0.5 rounded text-red-500">ИСТОЩЕН</span>
                                </div>
                                <div class="text-2xl font-black uppercase tracking-widest text-white border-b border-white/10 pb-2 inline-block">
                                    {{ promo.code }}
                                </div>
                            </div>
                            <button @click="deleteCode(promo.id)" class="text-white/10 hover:text-red-500 p-2 rounded-lg transition-colors bg-white/5 hover:bg-red-500/10">
                                <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            </button>
                        </div>

                        <div class="my-4 z-10">
                            <span class="text-4xl font-black italic tracking-tighter" :class="isDepleted(promo.used_count, promo.max_uses) ? 'text-white/40' : 'text-white'">
                                +{{ Math.floor(promo.value) }} <span class="text-sm font-mono" :class="isDepleted(promo.used_count, promo.max_uses) ? '' : 'text-purple-500'">₽</span>
                            </span>
                        </div>

                        <div class="mt-auto z-10">
                            <div class="flex justify-between text-[10px] uppercase font-black tracking-widest mb-2 text-white/40">
                                <span>Активаций</span>
                                <span :class="isDepleted(promo.used_count, promo.max_uses) ? 'text-red-500' : 'text-white'">{{ promo.used_count }} / {{ promo.max_uses }}</span>
                            </div>
                            <div class="w-full h-2 bg-white/5 rounded-full overflow-hidden">
                                <div class="h-full transition-all duration-1000"
                                     :class="isDepleted(promo.used_count, promo.max_uses) ? 'bg-red-500' : 'bg-purple-500 shadow-[0_0_10px_rgba(147,51,234,0.5)]'"
                                     :style="{ width: `${getProgress(promo.used_count, promo.max_uses)}%` }">
                                </div>
                            </div>
                        </div>

                        <div class="absolute -right-4 -bottom-8 text-8xl opacity-[0.02] font-black italic pointer-events-none select-none">
                            {{ promo.code }}
                        </div>
                    </div>

                </div>
            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
@reference "../../../css/app.css";
</style>
