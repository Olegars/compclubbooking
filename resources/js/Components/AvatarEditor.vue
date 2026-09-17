<script setup lang="ts">
import { computed, onUnmounted, ref } from 'vue'
import { useForm, usePage } from '@inertiajs/vue3'

withDefaults(defineProps<{
    compact?: boolean
}>(), {
    compact: false,
})

const page = usePage()
const fileInput = ref<HTMLInputElement | null>(null)
const preview = ref<string | null>(null)

const currentUrl = computed(() => {
    const p = page.props as any
    return String(p.user?.avatar_url || p.auth?.user?.avatar_url || '')
})
const flashSuccess = computed(() => String((page.props as any).flash?.success || ''))

const displayUrl = computed(() => preview.value || currentUrl.value)
const initial = computed(() => {
    const p = page.props as any
    const name = String(p.user?.name || p.auth?.user?.name || 'S')
    return (name[0] || 'S').toUpperCase()
})

const form = useForm({
    photo: null as File | null,
    stylize: false,
})

const onFile = (event: Event) => {
    const input = event.target as HTMLInputElement
    const file = input.files?.[0] || null
    form.photo = file
    if (preview.value) {
        URL.revokeObjectURL(preview.value)
    }
    preview.value = file ? URL.createObjectURL(file) : null
}

const save = () => {
    if (!form.photo || form.processing) {
        return
    }
    form.post('/account/profile/avatar', {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            if (preview.value) {
                URL.revokeObjectURL(preview.value)
            }
            preview.value = null
            form.photo = null
            form.stylize = false
            if (fileInput.value) {
                fileInput.value.value = ''
            }
        },
    })
}

onUnmounted(() => {
    if (preview.value) {
        URL.revokeObjectURL(preview.value)
    }
})
</script>

<template>
    <div :class="compact ? 'w-full' : 'space-y-4'">
        <div :class="compact ? 'flex flex-col items-center gap-3' : 'flex flex-col sm:flex-row items-center gap-5'">
            <button
                type="button"
                class="relative w-16 h-16 md:w-32 md:h-32 rounded-full bg-black flex items-center justify-center text-2xl md:text-5xl font-black text-[#22c55e] italic border-2 border-[#22c55e]/30 overflow-hidden shadow-[0_0_40px_rgba(34,197,94,0.1)] shrink-0 group"
                @click="fileInput?.click()"
            >
                <img v-if="displayUrl" :src="displayUrl" alt="" class="w-full h-full object-cover" />
                <span v-else>{{ initial }}</span>
                <span class="absolute inset-0 bg-black/55 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center text-[8px] md:text-[9px] uppercase tracking-widest font-black text-white">
                    Фото
                </span>
            </button>

            <div :class="compact ? 'w-full text-center md:text-center' : 'w-full space-y-3'">
                <input
                    ref="fileInput"
                    type="file"
                    accept="image/jpeg,image/png,image/webp,image/gif"
                    class="hidden"
                    @change="onFile"
                />
                <div :class="compact ? 'flex flex-col items-center gap-2' : 'flex flex-wrap items-center gap-3'">
                    <button
                        type="button"
                        class="px-4 py-2 rounded-xl border border-white/15 text-[9px] font-black uppercase tracking-widest text-white/70 hover:border-[#22c55e]/50 hover:text-[#22c55e] transition-colors"
                        @click="fileInput?.click()"
                    >
                        {{ form.photo ? 'Другое фото' : 'Выбрать фото' }}
                    </button>
                    <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                        <input
                            v-model="form.stylize"
                            type="checkbox"
                            class="w-4 h-4 rounded border-white/20 bg-black text-[#22c55e] accent-[#22c55e] focus:ring-0"
                        />
                        <span class="text-[10px] uppercase tracking-widest text-white/50 font-black">
                            Стилизовать под клубный формат
                        </span>
                    </label>
                </div>
                <p v-if="form.stylize" class="text-[10px] text-white/30 mt-2 leading-snug">
                    Снимок и образец клубного аватара уйдут в DeepSeek — в профиль встанет то, что вернёт модель.
                </p>
                <button
                    v-if="form.photo"
                    type="button"
                    :disabled="form.processing"
                    class="mt-3 w-full sm:w-auto px-5 py-3 rounded-xl bg-white/5 border border-white/10 text-[10px] font-black uppercase tracking-[0.2em] text-white hover:bg-[#22c55e] hover:text-black hover:border-[#22c55e] transition-all disabled:opacity-30"
                    @click="save"
                >
                    {{ form.processing
                        ? (form.stylize ? 'Стилизуем…' : 'Сохранение…')
                        : (form.stylize ? 'Стилизовать и сохранить' : 'Сохранить фото') }}
                </button>
                <div v-if="form.errors.photo" class="text-red-500 text-[10px] uppercase mt-2">{{ form.errors.photo }}</div>
                <div v-else-if="flashSuccess" class="text-[#22c55e] text-[10px] uppercase mt-2 tracking-widest">{{ flashSuccess }}</div>
            </div>
        </div>
    </div>
</template>
