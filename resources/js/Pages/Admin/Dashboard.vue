<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'
import MainLayout from '@/Layouts/MainLayout.vue'

const props = defineProps<{ stats: any }>()

const searchPhone = ref('')
const foundUser = ref<any>(null)
const depositAmount = ref(500)
const isProcessing = ref(false)

const search = async () => {
    if (searchPhone.value.length < 4) return
    const { data } = await axios.get(`/admin/search-user?phone=${searchPhone.value}`)
    foundUser.value = data
}

const handleDeposit = async () => {
    if (!foundUser.value || isProcessing.value) return
    isProcessing.value = true

    try {
        const { data } = await axios.post('/admin/manual-deposit', {
            user_id: foundUser.value.id,
            amount: depositAmount.value
        })
        foundUser.value.wallet.balance = data.new_balance
        alert('Баланс пополнен!')
    } catch (e) {
        alert('Ошибка пополнения')
    } finally {
        isProcessing.value = false
    }
}
</script>

<template>
    <MainLayout>
        <div class="max-w-6xl mx-auto space-y-8 animate-in fade-in duration-500">
            <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                <div v-for="(val, label) in stats" :key="label" class="bg-[#0f172a] border border-cyan-500/20 p-6 rounded-3xl">
                    <span class="text-[10px] text-cyan-500/50 uppercase font-black tracking-[0.3em]">{{ label }}</span>
                    <div class="text-4xl font-black text-white mt-2">{{ val }}</div>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-8">
                <div class="bg-[#0a0a0a] border border-white/5 rounded-[40px] p-10">
                    <h2 class="text-2xl font-black text-white uppercase italic mb-8 flex items-center gap-3">
                        <span class="w-2 h-8 bg-cyan-500"></span>
                        Прием наличных
                    </h2>

                    <div class="space-y-6">
                        <div>
                            <label class="text-[10px] text-white/30 uppercase font-black mb-2 block">Поиск гостя (Телефон)</label>
                            <input v-model="searchPhone" @input="search" type="text" placeholder="+7 (___) ___ - __ - __"
                                   class="w-full bg-black border border-white/10 rounded-2xl p-5 text-white font-mono focus:border-cyan-500 outline-none transition-all" />
                        </div>

                        <div v-if="foundUser" class="bg-cyan-500/5 border border-cyan-500/20 rounded-3xl p-6 animate-in zoom-in">
                            <div class="flex justify-between items-start mb-6">
                                <div>
                                    <div class="text-xl font-black text-white uppercase">{{ foundUser.name }}</div>
                                    <div class="text-xs text-white/40 font-mono">{{ foundUser.phone }}</div>
                                </div>
                                <div class="text-right">
                                    <div class="text-[10px] text-cyan-500 uppercase font-black">Текущий баланс</div>
                                    <div class="text-2xl font-black text-white">{{ foundUser.wallet?.balance }} ₽</div>
                                </div>
                            </div>

                            <div class="flex gap-3">
                                <input v-model="depositAmount" type="number" class="flex-grow bg-black border border-white/10 rounded-xl p-4 text-white font-black" />
                                <button @click="handleDeposit" :disabled="isProcessing"
                                        class="bg-cyan-500 hover:bg-cyan-400 text-black font-black px-8 rounded-xl uppercase text-xs tracking-widest transition-all">
                                    {{ isProcessing ? '...' : 'Пополнить' }}
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="bg-[#0a0a0a] border border-white/5 rounded-[40px] p-10 relative overflow-hidden">
                    <h2 class="text-2xl font-black text-white uppercase italic mb-8">Состояние узлов</h2>
                    <div class="grid grid-cols-5 gap-3">
                        <div v-for="i in 20" :key="i"
                             class="aspect-square rounded-xl border border-white/5 flex flex-col items-center justify-center group cursor-help transition-all hover:border-cyan-500/50"
                             :class="i < 6 ? 'bg-cyan-500/10 border-cyan-500/30' : 'bg-white/2'">
                            <span class="text-[10px] font-black" :class="i < 6 ? 'text-cyan-500' : 'text-white/20'">{{ i }}</span>
                            <div v-if="i < 6" class="w-1 h-1 rounded-full bg-cyan-500 mt-1 animate-pulse"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </MainLayout>
</template>
