<script setup lang="ts">
import { computed, ref, watch } from 'vue'

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

const filtered = computed(() => {
    const q = (props.modelValue || '').trim().toLowerCase()
    if (q.length < minChars.value) return []
    const list = props.suggestions || []
    return list.filter(s => s.toLowerCase().includes(q)).slice(0, 12)
})

watch(() => props.modelValue, (v) => {
    emit('search', v || '')
    open.value = (v || '').trim().length >= minChars.value
    active.value = -1
})

const pick = (value: string) => {
    emit('update:modelValue', value)
    open.value = false
}

const onBlur = () => {
    window.setTimeout(() => { open.value = false }, 150)
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
            :value="modelValue"
            type="text"
            autocomplete="off"
            :placeholder="placeholder || `от ${minChars} букв — подсказки`"
            :class="inputClass"
            @input="emit('update:modelValue', ($event.target as HTMLInputElement).value)"
            @focus="open = (modelValue || '').trim().length >= minChars"
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
