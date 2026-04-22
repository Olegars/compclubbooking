<script setup lang="ts">
import { ref } from 'vue'
import { router, Head } from '@inertiajs/vue3'
import MainLayout from '@/Layouts/MainLayout.vue'

const phone = ref('')
const code = ref('')
const step = ref(1) // 1 - ввод телефона, 2 - ввод кода
const isLoading = ref(false)
const error = ref('')

// Шаг 1: Запрос кода
const requestSms = async () => {
    if (phone.value.length < 10) {
        error.value = 'Введите корректный номер'
        return
    }

    isLoading.value = true
    error.value = ''

    // Отправляем запрос через axios (или fetch), так как мы не хотим менять страницу
    try {
        const response = await window.axios.post(route('auth.send-code'), { phone: phone.value })
        if (response.data.status === 'success') {
            step.value = 2
        }
    } catch (e) {
        error.value = 'Ошибка связи с сервером (Node Offline)'
    } finally {
        isLoading.value = false
    }
}

// Шаг 2: Вход в систему
const verifySms = () => {
    if (code.value.length !== 4) return

    isLoading.value = true
    error.value = ''

    // Тут используем Inertia, так как при успехе сервер сделает редирект на Dashboard
    router.post(route('auth.verify-code'), {
        phone: phone.value,
        code: code.value
    }, {
        onError: (errors) => {
            error.value = errors.code || 'Доступ запрещен. Неверный ключ.'
            isLoading.value = false
            code.value = ''
        }
    })
}
</script>

<template>
    <MainLayout>
        <Head title="Login Terminal" />

        <div class="flex-grow flex items-center justify-center p-6">
            <div class="w-full max-w-md bg-white/[0.02] border border-white/10 rounded-3xl p-10 backdrop-blur-md">

                <div class="text-center mb-10">
                    <h2 class="text-3xl font-bomber italic text-white uppercase tracking-widest mb-2">AUTH_GATEWAY</h2>
                    <p class="text-[10px] text-reactor font-mono uppercase tracking-[0.2em]">Введите идентификатор оператора</p>
                </div>

                <div v-if="error" class="mb-6 p-4 bg-red-500/10 border border-red-500/50 rounded-lg text-red-500 text-xs font-mono uppercase text-center animate-pulse">
                    [ ERROR ] {{ error }}
                </div>

                <div v-if="step === 1" class="space-y-6">
                    <div>
                        <label class="block text-[10px] uppercase text-white/30 tracking-[0.2em] mb-2 font-black italic">Phone Number</label>
                        <input
                            v-model="phone"
                            type="tel"
                            placeholder="+7 (999) 000-00-00"
                            class="w-full bg-black/50 border border-white/10 rounded-xl px-6 py-4 text-xl text-white font-mono focus:border-reactor focus:ring-1 focus:ring-reactor outline-none transition-all placeholder:text-white/20"
                            @keyup.enter="requestSms"
                        >
                    </div>

                    <button
                        @click="requestSms"
                        :disabled="isLoading"
                        class="w-full py-4 rounded-xl font-bomber text-lg tracking-widest uppercase transition-all"
                        :class="isLoading ? 'bg-white/10 text-white/30 cursor-not-allowed' : 'bg-reactor text-black hover:bg-white hover:text-black hover:shadow-[0_0_30px_rgba(34,197,94,0.4)]'"
                    >
                        {{ isLoading ? 'Соединение...' : 'Запросить доступ' }}
                    </button>
                </div>

                <div v-if="step === 2" class="space-y-6">
                    <div class="text-center mb-6">
                        <p class="text-xs text-white/50 font-mono">Код авторизации отправлен на терминал:</p>
                        <p class="text-reactor font-black mt-1">{{ phone }}</p>
                        <p class="text-[9px] text-white/20 mt-4 italic font-sans">(Для теста используй мастер-код: 0451)</p>
                    </div>

                    <div>
                        <input
                            v-model="code"
                            type="text"
                            maxlength="4"
                            placeholder="----"
                            class="w-full bg-black/50 border border-white/10 rounded-xl px-6 py-4 text-4xl text-center text-white font-mono tracking-[1em] focus:border-reactor focus:ring-1 focus:ring-reactor outline-none transition-all placeholder:text-white/20"
                            @keyup.enter="verifySms"
                        >
                    </div>

                    <button
                        @click="verifySms"
                        :disabled="isLoading || code.length !== 4"
                        class="w-full py-4 rounded-xl font-bomber text-lg tracking-widest uppercase transition-all"
                        :class="isLoading || code.length !== 4 ? 'bg-white/10 text-white/30 cursor-not-allowed' : 'bg-reactor text-black hover:bg-white hover:shadow-[0_0_30px_rgba(34,197,94,0.4)]'"
                    >
                        {{ isLoading ? 'Проверка...' : 'Войти в систему' }}
                    </button>

                    <button @click="step = 1; error = ''" class="w-full text-[10px] text-white/30 hover:text-white uppercase tracking-widest mt-4">
                        [ Отмена / Изменить номер ]
                    </button>
                </div>

            </div>
        </div>
    </MainLayout>
</template>
