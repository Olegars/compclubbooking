<script setup lang="ts">
import { computed } from 'vue'

export type BookingGameItem = {
    id: number
    title: string
    platform?: string | null
    billing_mode?: string
    unit_price_minor: number
    billing_unit_minutes?: number
    is_paid?: boolean
    is_installed?: boolean
    is_available: boolean
    available_accounts?: number
    required_accounts?: number
}

const props = defineProps<{
    isOpen: boolean
    games: BookingGameItem[]
    selectedIds: number[]
    hasSeats: boolean
    isLoading: boolean
    error?: string
    formatPrice: (game: BookingGameItem) => string
    billingNote: (game: BookingGameItem) => string
    blockReason: (game: BookingGameItem) => string
}>()

const emit = defineEmits<{
    (e: 'close'): void
    (e: 'update:selectedIds', ids: number[]): void
    (e: 'confirm'): void
}>()

const selectedCount = computed(() => props.selectedIds.length)
const selectableCount = computed(() => props.games.filter(g => g.is_available).length)

const isChecked = (id: number) => props.selectedIds.includes(id)

const toggleGame = (game: BookingGameItem) => {
    if (!game.is_available) return
    // Только одна платная игра на бронь
    const next = isChecked(game.id) ? [] : [game.id]
    emit('update:selectedIds', next)
}
</script>

<template>
    <Transition name="modal">
        <div v-if="isOpen" class="fixed inset-0 flex items-center justify-center p-4" style="z-index: 9999999 !important;">
            <div class="absolute inset-0 bg-black/70 backdrop-blur-sm" @click="emit('close')"></div>

            <div class="relative w-full max-w-[520px] max-h-[min(82vh,720px)] bg-[#0a0a0a] border border-white/10 rounded-[14px] shadow-[0_0_80px_rgba(0,0,0,1)] overflow-hidden z-[10000000] flex flex-col">
                <div class="h-1 bg-gradient-to-r from-transparent via-[#22c55e] to-transparent shrink-0"></div>

                <div class="p-6 sm:p-8 flex flex-col min-h-0 flex-1">
                    <div class="flex justify-between items-start mb-5 border-b border-white/5 pb-4 shrink-0">
                        <div class="pr-4">
                            <h3 class="text-[#22c55e] text-2xl font-black italic tracking-tighter uppercase leading-none">
                                Платные игры
                            </h3>
                            <p class="text-white/40 text-[10px] uppercase font-bold tracking-widest mt-2 leading-relaxed">
                                Забронируйте заранее — так аккаунт будет закреплён за вашей сессией
                            </p>
                        </div>
                        <button type="button" @click="emit('close')" class="text-white/20 hover:text-white transition-all text-3xl leading-none shrink-0">&times;</button>
                    </div>

                    <div class="flex-1 min-h-0 overflow-y-auto custom-scroll -mx-1 px-1">
                        <p v-if="!hasSeats" class="py-10 text-center text-[10px] text-red-500 font-black uppercase tracking-widest leading-relaxed">
                            Сначала выберите места на карте,<br>затем можно добавить игры
                        </p>
                        <p v-else-if="isLoading" class="py-10 text-center text-[10px] text-white/30 uppercase tracking-widest animate-pulse">
                            Проверяем доступность...
                        </p>
                        <p v-else-if="error" class="py-10 text-center text-[10px] text-red-400 uppercase tracking-widest">
                            {{ error }}
                        </p>
                        <p v-else-if="!games.length" class="py-10 text-center text-[10px] text-white/20 uppercase tracking-widest leading-relaxed">
                            Нет платных игр в этом клубе.<br>Бесплатные и незабронированные аккаунты — через shell
                        </p>
                        <div v-else class="space-y-2 pb-2">
                            <button
                                v-for="game in games"
                                :key="game.id"
                                type="button"
                                @click="toggleGame(game)"
                                :disabled="!game.is_available"
                                :class="[
                                    'w-full text-left flex items-start gap-3 p-3.5 rounded-2xl border transition-all',
                                    !game.is_available
                                        ? 'border-white/5 bg-white/[0.01] opacity-50 cursor-not-allowed'
                                        : isChecked(game.id)
                                            ? 'border-[#22c55e]/60 bg-[#22c55e]/10 cursor-pointer'
                                            : 'border-white/10 bg-white/[0.02] hover:border-[#22c55e]/40 cursor-pointer'
                                ]"
                            >
                                <span                     :class="[
                                    'mt-0.5 size-4 rounded-full border flex items-center justify-center shrink-0 text-[8px] font-black',
                                    isChecked(game.id) && game.is_available
                                        ? 'bg-[#22c55e] border-[#22c55e] text-black'
                                        : 'border-white/20 text-transparent'
                                ]">●</span>
                                <div class="min-w-0 flex-1">
                                    <div :class="['text-[12px] font-black uppercase truncate',
                                                  game.is_available ? 'text-white' : 'text-white/50 line-through']">
                                        {{ game.title }}
                                    </div>
                                    <div v-if="game.is_available" class="text-[8px] text-white/30 uppercase mt-1 truncate">
                                        {{ [game.platform, billingNote(game)].filter(Boolean).join(' · ') }}
                                    </div>
                                    <div v-else class="text-[8px] text-amber-500/80 uppercase mt-1 leading-snug">
                                        {{ blockReason(game) }}
                                    </div>
                                </div>
                                <span :class="['text-[10px] font-black whitespace-nowrap shrink-0',
                                               game.is_available ? 'text-[#22c55e]' : 'text-white/30']">
                                    {{ formatPrice(game) }}
                                </span>
                            </button>
                        </div>
                    </div>

                    <div class="pt-5 shrink-0 border-t border-white/5 mt-4 space-y-3">
                        <div class="flex justify-between text-[9px] uppercase font-black tracking-widest text-white/35 px-1">
                            <span>Доступно: {{ selectableCount }}</span>
                            <span :class="selectedCount ? 'text-[#22c55e]' : ''">
                                {{ selectedCount ? 'Выбрана 1 игра' : 'Игра не выбрана' }}
                            </span>
                        </div>
                        <button
                            type="button"
                            @click="emit('confirm')"
                            :disabled="isLoading || (selectedCount > 0 && !hasSeats)"
                            class="w-full p-4 rounded-2xl text-[11px] font-black uppercase tracking-widest transition-all cursor-pointer disabled:opacity-30 disabled:grayscale disabled:cursor-not-allowed"
                            :class="selectedCount
                                ? 'bg-[#22c55e] text-black hover:bg-[#1ea34d] shadow-[0_0_24px_rgba(34,197,94,0.25)]'
                                : 'bg-white/5 border border-white/10 text-white hover:border-[#22c55e]/40'"
                        >
                            {{ selectedCount ? `Забронировать · ${selectedCount}` : 'Закрыть без выбора' }}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </Transition>
</template>

<style scoped>
.modal-enter-active, .modal-leave-active { transition: all 0.3s cubic-bezier(0.16, 1, 0.3, 1); }
.modal-enter-from, .modal-leave-to { opacity: 0; transform: scale(0.95) translateY(10px); }
.custom-scroll::-webkit-scrollbar { width: 5px; }
.custom-scroll::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.12); border-radius: 8px; }
</style>
