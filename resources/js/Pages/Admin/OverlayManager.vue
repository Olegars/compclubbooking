<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'

// --- ИНТЕРФЕЙСЫ ---
interface OverlayContent {
    text?: string;
    list?: string[];
    [key: string]: any;
}

interface OverlayBlock {
    id: number;
    block_position: string;
    title: string;
    type: string;
    content: OverlayContent;
    is_active: boolean;
}

const overlays = ref<OverlayBlock[]>([])
const isProcessing = ref(false)

// --- ЗАГРУЗКА ---
const fetchOverlays = async () => {
    try {
        const response = await axios.get('/api/overlays')
        overlays.value = response.data
    } catch (error) {
        console.error("Ошибка загрузки оверлеев", error)
    }
}

// --- СОХРАНЕНИЕ ---
const saveOverlay = async (block: OverlayBlock) => {
    if (isProcessing.value) return
    isProcessing.value = true

    try {
        await axios.put(`/api/overlays/${block.id}`, {
            title: block.title,
            type: block.type,
            content: block.content,
            is_active: block.is_active
        })

        // Временная зеленая подсветка кнопки или тост
        console.log(`Оверлей ${block.block_position} сохранен`)
    } catch (error) {
        alert('Ошибка сохранения блока!')
        console.error(error)
    } finally {
        isProcessing.value = false
    }
}

onMounted(() => {
    fetchOverlays()
})

// --- ВСПОМОГАТЕЛЬНАЯ ФУНКЦИЯ ДЛЯ КРАСИВЫХ ИМЕН ---
const formatPositionName = (pos: string) => {
    const map: Record<string, string> = {
        'top_left': 'Верхний Левый',
        'top_right': 'Верхний Правый',
        'mid_left': 'Средний Левый',
        'mid_right': 'Средний Правый',
        'bottom_left': 'Нижний Левый',
        'bottom_right': 'Нижний Правый'
    }
    return map[pos] || pos
}
</script>

<template>
    <AdminLayout>
        <div class="max-w-7xl mx-auto space-y-8 animate-in fade-in duration-500 pb-10 font-mono text-white p-6">

            <div class="bg-[#0a0a0a] border border-purple-500/10 rounded-[3rem] p-10 shadow-2xl flex items-center justify-between">
                <div>
                    <h2 class="text-2xl font-black uppercase italic mb-2 flex items-center gap-4 tracking-tighter">
                        <span class="w-2 h-10 bg-purple-500 rounded-full shadow-[0_0_15px_rgba(168,85,247,0.5)]"></span>
                        Shell Overlays
                    </h2>
                    <p class="text-[10px] text-white/40 uppercase tracking-[0.3em] italic ml-6">
                        Управление контентом экранов блокировки в залах
                    </p>
                </div>
                <div class="w-16 h-16 rounded-2xl bg-purple-500/10 border border-purple-500/30 flex items-center justify-center text-2xl">
                    🖥️
                </div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">

                <div v-for="block in overlays" :key="block.id"
                     class="bg-[#0a0a0a] border border-white/5 rounded-[2.5rem] p-8 shadow-xl relative overflow-hidden transition-all group"
                     :class="{ '!border-[#22c55e]/30 !shadow-[0_0_20px_rgba(34,197,94,0.05)]': block.is_active }">

                    <div class="flex justify-between items-start mb-6 border-b border-white/5 pb-4">
                        <div>
              <span class="text-[10px] text-purple-500/50 uppercase font-black tracking-[0.3em] italic">
                {{ block.block_position }}
              </span>
                            <div class="text-lg font-black mt-1 tracking-tighter italic">
                                {{ formatPositionName(block.block_position) }}
                            </div>
                        </div>

                        <label class="relative inline-flex items-center cursor-pointer">
                            <input type="checkbox" v-model="block.is_active" class="sr-only peer" @change="saveOverlay(block)">
                            <div class="w-11 h-6 bg-black border border-white/20 rounded-full peer
                          peer-checked:after:translate-x-full peer-checked:border-[#22c55e] peer-checked:bg-[#22c55e]/20
                          after:content-[''] after:absolute after:top-0.5 after:left-[2px] after:bg-white/50
                          peer-checked:after:bg-[#22c55e] after:rounded-full after:h-5 after:w-5 after:transition-all"></div>
                        </label>
                    </div>

                    <div class="space-y-5" :class="{ 'opacity-50 pointer-events-none': !block.is_active }">

                        <div>
                            <label class="block text-[10px] text-white/40 uppercase tracking-[0.2em] font-bold mb-2">Отображаемый Заголовок</label>
                            <input v-model="block.title" type="text"
                                   class="w-full bg-black border border-white/10 rounded-2xl p-4 text-white focus:border-purple-500 outline-none text-sm transition-all" />
                        </div>

                        <div>
                            <label class="block text-[10px] text-white/40 uppercase tracking-[0.2em] font-bold mb-2">Формат вывода</label>
                            <select v-model="block.type"
                                    class="w-full bg-black border border-white/10 rounded-2xl p-4 text-white focus:border-purple-500 outline-none text-sm appearance-none">
                                <option value="text">Простой текст / Промо</option>
                                <option value="list">Список (Игры / Топ)</option>
                                <option value="system">Системные данные</option>
                            </select>
                        </div>

                        <div v-if="block.type === 'text'">
                            <label class="block text-[10px] text-white/40 uppercase tracking-[0.2em] font-bold mb-2">Текст</label>
                            <textarea v-model="block.content.text" rows="3"
                                      class="w-full bg-black border border-white/10 rounded-2xl p-4 text-white focus:border-purple-500 outline-none text-sm custom-scrollbar"></textarea>
                        </div>

                        <div v-if="block.type === 'system'" class="p-4 bg-white/[0.02] border border-white/5 rounded-2xl text-xs text-white/50 italic text-center">
                            Данные будут генерироваться терминалом автоматически (CPU, GPU, Пинг).
                        </div>

                        <button @click="saveOverlay(block)"
                                class="w-full mt-4 bg-purple-500/10 hover:bg-purple-500 text-purple-500 hover:text-white border border-purple-500/30
                           font-black py-4 rounded-2xl uppercase text-[10px] tracking-[0.2em] transition-all active:scale-95">
                            Синхронизировать
                        </button>
                    </div>
                </div>

            </div>
        </div>
    </AdminLayout>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar { width: 4px; }
.custom-scrollbar::-webkit-scrollbar-thumb { background: rgba(255,255,255,0.05); border-radius: 10px; }
</style>
