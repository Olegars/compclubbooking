<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'

const props = defineProps<{
    modelValue: string
    label: string
    suggestions?: string[]
    placeholder?: string
    minChars?: number
    inputClass?: string
    labelClass?: string
}>()

const emit = defineEmits<{
    'update:modelValue': [value: string]
    search: [query: string]
}>()

const minChars = computed(() => props.minChars ?? 3)
const open = ref(false)
const active = ref(-1)
const focused = ref(false)
const suppressOpen = ref(false)
const inputEl = ref<HTMLInputElement | null>(null)

const filtered = computed(() => {
    const q = (props.modelValue || '').trim().toLowerCase()
    if (q.length < minChars.value) return []
    const list = props.suggestions || []
    return list.filter(s => s.toLowerCase().includes(q)).slice(0, 12)
})

watch(() => props.modelValue, (v) => {
    emit('search', v || '')
    active.value = -1
    // Не открывать при автозаполнении / выборе из списка
    if (suppressOpen.value || !focused.value) {
        open.value = false
        return
    }
    open.value = (v || '').trim().length >= minChars.value
})

const pick = (value: string) => {
    suppressOpen.value = true
    emit('update:modelValue', value)
    open.value = false
    nextTick(() => {
        suppressOpen.value = false
        // Уводим фокус, чтобы соседние автозаполненные поля не открыли списки
        inputEl.value?.blur()
    })
}

const onFocus = () => {
    focused.value = true
    open.value = (props.modelValue || '').trim().length >= minChars.value
}

const onBlur = () => {
    focused.value = false
    window.setTimeout(() => { open.value = false }, 150)
}

const onInput = (e: Event) => {
    emit('update:modelValue', (e.target as HTMLInputElement).value)
}

const onKeydown = (e: KeyboardEvent) => {
    if (!open.value || !filtered.value.length) return
    if (e.key === 'ArrowDown') {
        e.preventDefault()
        active.value = Math.min(active.value + 1, filtered.value.length - 1)
    } else if (e.key === 'ArrowUp') {
        e.preventDefault()
        active.value = Math.max(active.value - 1, 0)
    } else if (e.key === 'Enter' && active.value >= 0) {
        e.preventDefault()
        pick(filtered.value[active.value])
    } else if (e.key === 'Escape') {
        open.value = false
    }
}
</script>

<template>
    <div class="relative">
        <label v-if="label" :class="labelClass">{{ label }}</label>
        <input
            ref="inputEl"
            :value="modelValue"
            type="text"
            autocomplete="off"
            :placeholder="placeholder || `от ${minChars} букв — подсказки`"
            :class="inputClass"
            @input="onInput"
            @focus="onFocus"
            @blur="onBlur"
            @keydown="onKeydown"
        />
        <div
            v-if="open && filtered.length"
            class="absolute z-50 left-0 right-0 mt-1 max-h-48 overflow-y-auto rounded-xl border border-amber-500/30 bg-[#0a0a0a] shadow-xl"
        >
            <button
                v-for="(item, i) in filtered"
                :key="item"
                type="button"
                class="w-full text-left px-4 py-2.5 text-sm hover:bg-amber-500/10"
                :class="i === active ? 'bg-amber-500/15 text-amber-300' : 'text-white/80'"
                @mousedown.prevent="pick(item)"
            >
                {{ item }}
            </button>
        </div>
    </div>
</template>
