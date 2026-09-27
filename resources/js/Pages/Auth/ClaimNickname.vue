<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { Head, useForm } from '@inertiajs/vue3'
import MainLayout from '@/Layouts/MainLayout.vue'

const props = defineProps<{
    nickname: string
}>()

const inputRef = ref<HTMLInputElement | null>(null)

const form = useForm({
    name: props.nickname,
})

const submit = () => {
    form.post(route('auth.nickname.store'), {
        preserveScroll: true,
    })
}

onMounted(() => {
    inputRef.value?.focus()
    if (!form.errors.name) {
        inputRef.value?.select()
    }
})
</script>

<template>
    <MainLayout>
        <Head title="Ваш позывной" />

        <div class="flex-grow flex items-center justify-center p-6">
            <form
                class="w-full max-w-md bg-white/[0.02] border border-white/10 rounded-3xl p-10 backdrop-blur-md"
                @submit.prevent="submit"
            >
                <div class="text-center mb-8">
                    <h2 class="text-3xl font-bomber italic text-white uppercase tracking-widest mb-3">Ваш позывной</h2>
                    <p class="text-[11px] text-white/50 font-mono leading-relaxed">
                        Это подсказка, не окончательный ник. Кликните в поле и впишите свой — в профиль заходить не нужно.
                    </p>
                </div>

                <label class="block text-[10px] uppercase text-white/30 tracking-[0.2em] mb-2 font-black italic" for="nickname">
                    Ник
                </label>
                <input
                    id="nickname"
                    ref="inputRef"
                    v-model="form.name"
                    type="text"
                    maxlength="50"
                    autocomplete="nickname"
                    class="w-full bg-black/50 border border-white/10 rounded-xl px-6 py-4 text-xl text-white font-mono focus:border-reactor focus:ring-1 focus:ring-reactor outline-none transition-all"
                >

                <p v-if="form.errors.name" class="mt-3 text-red-500 text-[10px] uppercase tracking-wide">
                    {{ form.errors.name }}
                </p>
                <p v-else class="mt-3 text-[10px] text-white/30 font-mono">
                    Можно оставить как есть или сразу вписать свой тег.
                </p>

                <button
                    type="submit"
                    :disabled="form.processing || form.name.trim().length < 2"
                    class="mt-8 w-full py-4 rounded-xl font-bomber text-lg tracking-widest uppercase transition-all"
                    :class="form.processing || form.name.trim().length < 2 ? 'bg-white/10 text-white/30 cursor-not-allowed' : 'bg-reactor text-black hover:bg-white hover:shadow-[0_0_30px_rgba(34,197,94,0.4)]'"
                >
                    {{ form.processing ? 'Сохранение...' : 'Продолжить' }}
                </button>
            </form>
        </div>
    </MainLayout>
</template>
