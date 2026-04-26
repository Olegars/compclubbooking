<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const isOpen = ref(false)
const message = ref('')
const chatHistory = ref<{role: string, text: string}[]>([])
const isSending = ref(false)

const sendMessage = async () => {
    if (!message.value.trim() || isSending.value) return

    isSending.value = true
    const text = message.value
    chatHistory.value.push({ role: 'user', text })
    message.value = ''

    try {
        await axios.post('/api/admin/call', { message: text })
        // Здесь можно добавить логику ожидания ответа через Echo
    } catch (e) {
        chatHistory.value.push({ role: 'system', text: 'Ошибка доставки сигнала.' })
    } finally {
        isSending.value = false
    }
}
</script>

<template>
    <div class="fixed bottom-8 right-8 z-[9999]">
        <button @click="isOpen = !isOpen"
                class="w-16 h-16 bg-[#22c55e] text-black rounded-full shadow-[0_0_30px_rgba(34,197,94,0.4)] flex items-center justify-center hover:scale-110 transition-all active:scale-95">
            <svg v-if="!isOpen" class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
            </svg>
            <span v-else class="text-2xl font-black">✕</span>
        </button>

        <div v-if="isOpen"
             class="absolute bottom-20 right-0 w-80 bg-[#0a0a0a] border-2 border-[#22c55e]/30 rounded-[2rem] overflow-hidden shadow-2xl animate-in slide-in-from-bottom-5">
            <div class="bg-[#22c55e]/10 p-4 border-b border-[#22c55e]/20">
                <div class="text-[10px] text-[#22c55e] font-black uppercase tracking-widest italic">Связь с узлом</div>
                <div class="text-white font-black uppercase italic">Администратор</div>
            </div>

            <div class="h-64 p-4 overflow-y-auto space-y-3 font-mono text-[11px]">
                <div v-if="chatHistory.length === 0" class="text-white/20 text-center py-10 italic">
                    Нужна помощь или возник вопрос? Напиши админу.
                </div>
                <div v-for="(msg, i) in chatHistory" :key="i"
                     :class="msg.role === 'user' ? 'text-right' : 'text-left'">
                    <span :class="msg.role === 'user' ? 'bg-[#22c55e]/10 text-[#22c55e]' : 'bg-white/5 text-white'"
                          class="inline-block px-3 py-2 rounded-xl border border-white/5">
                        {{ msg.text }}
                    </span>
                </div>
            </div>

            <div class="p-4 border-t border-white/5 bg-black/50">
                <div class="flex gap-2">
                    <input v-model="message"
                           @keyup.enter="sendMessage"
                           placeholder="Сообщение..."
                           class="flex-1 bg-white/5 border border-white/10 rounded-xl px-4 py-2 text-white text-xs outline-none focus:border-[#22c55e]/50" />
                    <button @click="sendMessage"
                            class="bg-[#22c55e] text-black px-3 rounded-xl hover:bg-[#2ae06d] transition-colors">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
