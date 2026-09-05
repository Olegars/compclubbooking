<script setup lang="ts">
import { computed, ref, watch } from 'vue'

type Section = { id: number; title: string; body: string }

const props = withDefaults(defineProps<{
    sections: Section[]
    acceptedIds: number[]
    canAccept?: boolean
    busy?: boolean
}>(), {
    canAccept: true,
    busy: false,
})

const emit = defineEmits<{
    accept: [id: number]
}>()

const acceptedSet = computed(() => new Set(props.acceptedIds))

const firstUnaccepted = computed(() => {
    return props.sections.find((section) => !acceptedSet.value.has(section.id))?.id ?? null
})

const openId = ref<number | null>(firstUnaccepted.value)

watch(firstUnaccepted, (id) => {
    if (id != null) openId.value = id
}, { immediate: true })

const toggle = (id: number) => {
    openId.value = openId.value === id ? null : id
}

const accept = (id: number) => {
    if (!props.canAccept || props.busy || acceptedSet.value.has(id)) return
    emit('accept', id)
}
</script>

<template>
    <div class="space-y-3">
        <div v-for="(section, index) in sections" :key="section.id"
             class="border rounded-2xl overflow-hidden"
             :class="openId === section.id ? 'border-[#22c55e]/30' : 'border-white/10'">
            <button type="button"
                    class="w-full flex items-center justify-between gap-4 px-5 py-4 text-left"
                    @click="toggle(section.id)">
                <div class="min-w-0">
                    <div class="text-white text-sm font-black uppercase italic truncate">
                        {{ index + 1 }}. {{ section.title }}
                    </div>
                    <div class="text-[10px] uppercase font-black tracking-widest mt-1"
                         :class="acceptedSet.has(section.id) ? 'text-[#22c55e]' : 'text-white/30'">
                        {{ acceptedSet.has(section.id) ? 'Принято' : 'Не принято' }}
                    </div>
                </div>
                <span class="shrink-0 text-white/40 text-xs font-black transition-transform"
                      :class="openId === section.id ? 'rotate-180' : ''">▾</span>
            </button>
            <div v-if="openId === section.id" class="px-5 pb-5 border-t border-white/5">
                <p class="text-white/50 text-xs font-bold leading-relaxed mt-4 whitespace-pre-wrap">{{ section.body }}</p>
                <button v-if="canAccept && !acceptedSet.has(section.id)" type="button"
                        :disabled="busy"
                        class="mt-4 px-5 py-3 bg-[#22c55e] text-black rounded-xl text-[10px] font-black uppercase tracking-widest disabled:opacity-40"
                        @click.stop="accept(section.id)">
                    Принимаю
                </button>
                <div v-else-if="acceptedSet.has(section.id)"
                     class="mt-4 text-[10px] uppercase font-black tracking-widest text-[#22c55e]">
                    Принято
                </div>
            </div>
        </div>
    </div>
</template>
