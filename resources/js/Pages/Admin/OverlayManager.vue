<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import AdminLayout from '@/Layouts/AdminLayout.vue'

// --- ИНТЕРФЕЙСЫ ---
interface Layer {
    type: 'video';
    value: string;
}

interface OverlayContent {
    layers: Layer[];
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

// Для загрузки файлов
const fileInput = ref<HTMLInputElement | null>(null)
const currentUploadBlock = ref<OverlayBlock | null>(null)

// --- API ЗАПРОСЫ ---
const fetchOverlays = async () => {
    try {
        const response = await axios.get('/admin/api/overlays')
        // Форматируем данные, чтобы всегда был один слой типа "video"
        overlays.value = response.data.map((block: any) => {
            let content = block.content
            if (typeof content === 'string') {
                try { content = JSON.parse(content) } catch (e) { content = {} }
            }
            if (!content || !content.layers || content.layers.length === 0) {
                content = { layers: [{ type: 'video', value: '' }] }
            } else {
                content.layers[0].type = 'video' // Жестко задаем тип
            }
            block.content = content
            return block
        })
    } catch (error) {
        console.error("Ошибка загрузки", error)
    }
}

const saveOverlay = async (block: OverlayBlock) => {
    if (isProcessing.value) return
    isProcessing.value = true

    try {
        const payload = {
            title: block.title,
            type: block.type,
            content: block.content, // Отправляем структуру с массивом layers (одно видео)
            is_active: block.is_active ? 1 : 0
        }

        const response = await axios.put(`/admin/api/overlays/${block.id}`, payload)

        if (response.data.status === 'success') {
            alert('REACTOR: ВИДЕО УСПЕШНО СИНХРОНИЗИРОВАНО!')
        }
    } catch (error: any) {
        console.error("ОШИБКА:", error.response?.data)
        alert('Ошибка при сохранении: ' + JSON.stringify(error.response?.data?.errors))
    } finally {
        isProcessing.value = false
    }
}

// --- ЛОГИКА ЗАГРУЗКИ ВИДЕО ---
const triggerUpload = (block: OverlayBlock) => {
    currentUploadBlock.value = block
    fileInput.value?.click()
}

const handleVideoUpload = async (event: Event) => {
    const target = event.target as HTMLInputElement
    if (!target.files?.length || !currentUploadBlock.value) return

    const file = target.files[0]
    const formData = new FormData()
    formData.append('video', file) // Бэкенд должен ловить 'video'

    try {
        isProcessing.value = true
        // Тебе нужно будет создать этот роут в Laravel, если его еще нет
        const response = await axios.post('/admin/api/upload-video', formData, {
            headers: { 'Content-Type': 'multipart/form-data' }
        })

        // Подставляем полученный URL прямо в инпут текущего блока
        currentUploadBlock.value.content.layers[0].value = response.data.url
        alert('Видео загружено! Нажмите "СИНХРОНИЗИРОВАТЬ" для отправки на экраны.')
    } catch (error) {
        alert('Ошибка при загрузке видео на сервер! Проверьте лимиты (upload_max_filesize).')
    } finally {
        isProcessing.value = false
        if (fileInput.value) fileInput.value.value = ''
        currentUploadBlock.value = null
    }
}

onMounted(fetchOverlays)

const formatPositionName = (pos: string) => {
    const map: Record<string, string> = {
        'top_left': 'Верхний Левый', 'top_right': 'Верхний Правый',
        'mid_left': 'Средний Левый', 'mid_right': 'Средний Правый',
        'bottom_left': 'Нижний Левый', 'bottom_right': 'Нижний Правый'
    }
    return map[pos] || pos
}
</script>

<template>
    <AdminLayout>
        <input type="file" ref="fileInput" class="hidden" accept="video/mp4,video/webm" @change="handleVideoUpload" />

        <div class="max-w-7xl mx-auto space-y-8 pb-10 font-mono text-white p-6">

            <div class="bg-[#0a0a0a] border border-purple-500/10 rounded-[1.125rem] p-10 flex items-center justify-between shadow-2xl">
                <div>
                    <h2 class="text-2xl font-black uppercase italic mb-2 flex items-center gap-4 tracking-tighter">
                        <span class="w-2 h-10 bg-purple-500 rounded-full shadow-[0_0_15px_rgba(168,85,247,0.5)]"></span>
                        REACTOR Media Center
                    </h2>
                    <p class="text-[10px] text-white/40 uppercase tracking-[0.3em] ml-6 italic">Video Injection System v3.0</p>
                </div>
                <div class="text-xs text-purple-500/50 font-black tracking-widest">2026 // SECTOR 0451</div>
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 animate-in fade-in duration-500">
                <div v-for="block in overlays" :key="block.id"
                     class="bg-[#0a0a0a] border border-white/5 rounded-[1rem] p-8 group transition-all hover:border-purple-500/20 shadow-xl relative overflow-hidden flex flex-col justify-between">

                    <div>
                        <div class="flex justify-between items-start mb-6 border-b border-white/5 pb-4">
                            <div>
                                <span class="text-[10px] text-purple-500/50 uppercase font-black tracking-widest">{{ block.block_position }}</span>
                                <div class="text-lg font-black italic tracking-tighter">{{ formatPositionName(block.block_position) }}</div>
                            </div>

                            <label class="relative inline-flex items-center cursor-pointer">
                                <input type="checkbox" v-model="block.is_active" class="sr-only peer">
                                <div class="w-11 h-6 bg-white/10 peer-focus:outline-none rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-gray-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all peer-checked:bg-green-500 shadow-[0_0_10px_rgba(34,197,94,0)] peer-checked:shadow-[0_0_15px_rgba(34,197,94,0.4)]"></div>
                                <span class="ml-3 text-[10px] font-bold uppercase tracking-wider" :class="block.is_active ? 'text-green-500' : 'text-white/30'">
                                    {{ block.is_active ? 'ТРАНСЛЯЦИЯ ВКЛ' : 'ВЫКЛЮЧЕНО' }}
                                </span>
                            </label>
                        </div>

                        <div class="space-y-4 mb-8">
                            <label class="text-[10px] text-white/40 uppercase tracking-widest font-bold block">Источник видео (URL .mp4)</label>

                            <div class="flex gap-3">
                                <input type="text" v-model="block.content.layers[0].value" placeholder="https://.../video.mp4"
                                       class="flex-1 bg-[#050505] border border-white/10 p-4 rounded-2xl text-xs outline-none focus:border-purple-500 transition-all text-white font-mono" />

                                <button @click="triggerUpload(block)" title="Загрузить видео с ПК"
                                        class="bg-white/5 hover:bg-purple-500 border border-white/10 hover:border-purple-500 text-white hover:text-black px-6 rounded-2xl transition-all flex items-center justify-center">
                                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                                        <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/>
                                        <path d="M7.646 1.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1-.708.708L8.5 2.707V11.5a.5.5 0 0 1-1 0V2.707L5.354 4.854a.5.5 0 1 1-.708-.708l3-3z"/>
                                    </svg>
                                </button>
                            </div>
                        </div>
                    </div>

                    <button @click="saveOverlay(block)" :disabled="isProcessing"
                            class="w-full bg-[#22c55e]/10 hover:bg-[#22c55e] text-[#22c55e] hover:text-black border border-[#22c55e]/30 py-4 rounded-2xl text-[11px] font-black uppercase tracking-[0.2em] transition-all disabled:opacity-50">
                        {{ isProcessing ? 'СИНХРОНИЗАЦИЯ...' : 'СИНХРОНИЗИРОВАТЬ БЛОК' }}
                    </button>
                </div>
            </div>

        </div>
    </AdminLayout>
</template>
