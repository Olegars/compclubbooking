<script setup lang="ts">
import { Head, useForm, usePage } from '@inertiajs/vue3'
import { computed } from 'vue'
import AdminLayout from '@/Layouts/AdminLayout.vue'
import { useClubName } from '@/Composables/useClubName'

const clubName = useClubName()

const props = defineProps<{
    settings: {
        cancel_before_minutes: number
        cancel_before_hours: number
    }
}>()

const page = usePage()
const flashSuccess = computed(() => (page.props as any).flash?.success as string | undefined)

const form = useForm({
    cancel_before_hours: props.settings.cancel_before_hours,
})

const previewMinutes = computed(() => Math.max(0, Math.round(Number(form.cancel_before_hours || 0) * 60)))

const previewLabel = computed(() => {
    const h = Number(form.cancel_before_hours || 0)
    if (h <= 0) return 'до момента начала брони'
    if (Number.isInteger(h)) return `за ${h} ч до начала`
    return `за ${h} ч (${previewMinutes.value} мин) до начала`
})

const save = () => {
    form.post('/admin/booking-settings', { preserveScroll: true })
}
</script>

<template>
    <Head :title="`${clubName} | Правила брони`" />
    <AdminLayout>
        <div class="max-w-3xl mx-auto space-y-8 animate-in fade-in duration-500 font-mono pb-20 px-4">
            <div class="bg-[#0a0a0a] border border-white/5 p-8 rounded-[1rem] shadow-2xl">
                <h1 class="text-3xl font-black uppercase italic text-cyan-400 tracking-tighter">
                    Правила <span class="text-white">брони</span>
                </h1>
                <p class="text-white/20 text-[10px] uppercase tracking-[0.4em] font-black mt-2 italic">
                    Самоотмена гостем · возврат на баланс
                </p>
            </div>

            <div
                v-if="flashSuccess"
                class="px-5 py-4 rounded-2xl border border-[#22c55e]/30 bg-[#22c55e]/10 text-[#22c55e] text-xs font-black uppercase tracking-wider"
            >
                {{ flashSuccess }}
            </div>

            <form
                class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-8 space-y-8"
                @submit.prevent="save"
            >
                <div>
                    <label class="block text-[10px] uppercase tracking-[0.3em] text-white/40 font-black italic mb-3">
                        Отмена возможна не позднее чем за
                    </label>
                    <div class="flex flex-wrap items-end gap-4">
                        <div class="flex-1 min-w-[140px]">
                            <input
                                v-model.number="form.cancel_before_hours"
                                type="number"
                                min="0"
                                max="168"
                                step="0.5"
                                class="w-full bg-black/40 border border-white/10 focus:border-cyan-500/50 rounded-xl px-5 py-4 text-2xl font-black text-white outline-none"
                            />
                        </div>
                        <span class="pb-4 text-sm font-black uppercase italic text-white/50 tracking-wider">часов до начала</span>
                    </div>
                    <p class="mt-4 text-[11px] text-white/35 italic leading-relaxed">
                        Пример: <span class="text-cyan-400/80">2</span> — гость может отменить бронь с полным возвратом
                        только если до старта осталось больше 2 часов. Позже кнопка отмены в кабинете недоступна,
                        оплата удерживается (вход / no-show).
                    </p>
                    <p class="mt-2 text-[11px] text-white/25 italic">
                        <span class="text-white/50">0</span> — отмена разрешена до самого старта.
                        Сейчас: <span class="text-cyan-400">{{ previewLabel }}</span>
                        · {{ previewMinutes }} мин.
                    </p>
                    <p v-if="form.errors.cancel_before_hours" class="mt-3 text-red-400 text-xs">
                        {{ form.errors.cancel_before_hours }}
                    </p>
                </div>

                <button
                    type="submit"
                    class="px-8 py-4 rounded-xl bg-cyan-500 text-black font-black uppercase tracking-widest text-[10px] hover:scale-[1.02] transition-all disabled:opacity-50"
                    :disabled="form.processing"
                >
                    {{ form.processing ? 'Сохранение…' : 'Сохранить' }}
                </button>
            </form>
        </div>
    </AdminLayout>
</template>
