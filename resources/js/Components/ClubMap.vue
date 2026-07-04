<template>
    <div class="map-wrapper w-full h-full flex justify-center items-center relative rounded-[38px] overflow-hidden border border-white/5 bg-[#020202]">
        <svg
            :viewBox="viewbox || safeConfig?.viewbox || '-10 -10 120 200'"
            preserveAspectRatio="xMidYMid meet"
            class="w-full h-auto max-h-[85vh] select-none overflow-visible transition-all duration-1000"
        >
            <defs>
                <filter id="wall-glow" x="-50%" y="-50%" width="200%" height="200%">
                    <feGaussianBlur stdDeviation="0.8" result="blur" />
                    <feMerge>
                        <feMergeNode in="blur" /><feMergeNode in="SourceGraphic" />
                    </feMerge>
                </filter>
            </defs>

            <g v-if="safeConfig?.walls" class="walls-layer">
                <path
                    v-for="(wall, i) in safeConfig.walls" :key="'w-'+i"
                    :d="wall.d"
                    fill="rgba(34,197,94,0.02)"
                    stroke="#22c55e"
                    stroke-width="0.4"
                    stroke-linejoin="round"
                    filter="url(#wall-glow)"
                />
            </g>

            <g v-if="safeConfig?.zoneRects?.length" class="zones-layer">
                <rect
                    v-for="(r, i) in safeConfig.zoneRects" :key="'zr-'+i"
                    :x="Number(r.x)" :y="Number(r.y)" :width="Number(r.w)" :height="Number(r.h)"
                    :fill="r.c || '#22c55e'"
                    :fill-opacity="r.c === '#4d4d4d' ? 1 : 0.25"
                    :stroke="r.c || '#22c55e'"
                    stroke-width="0.6"
                    rx="0"
                />
            </g>

            <g v-if="safeConfig?.labels" class="labels-layer">
                <text
                    v-for="(l, i) in safeConfig.labels" :key="'l-'+i"
                    :x="l.x" :y="l.y"
                    :transform="l.rotate ? `rotate(${l.rotate} ${l.x} ${l.y})` : ''"
                    :fill="l.color || '#ffffff'"
                    fill-opacity="0.85"
                    :font-size="l.size || 6"
                    font-weight="900"
                    font-family="Arial, sans-serif"
                    class="uppercase pointer-events-none"
                >
                    {{ l.content }}
                </text>
            </g>

            <g v-for="pc in computers" :key="'pc-'+pc.id" class="cursor-pointer group" @click="handleClick(pc.id.toString())">
                <rect
                    :x="Number(pc.x)" :y="Number(pc.y)"
                    width="6" height="4.5"
                    rx="0.5"
                    :fill="isOccupied(pc.id) ? '#111' : (isSelected(pc.id) ? '#22c55e' : '#001100')"
                    :stroke="isSelected(pc.id) ? '#fff' : '#22c55e'"
                    stroke-width="0.5"
                    class="transition-colors duration-300 group-hover:stroke-white"
                />
                <text
                    :x="Number(pc.x) + 3" :y="Number(pc.y) + 3.1"
                    font-size="2"
                    font-weight="900"
                    font-family="Arial, sans-serif"
                    text-anchor="middle"
                    :fill="isSelected(pc.id) ? '#000' : '#22c55e'"
                    class="pointer-events-none transition-colors duration-300"
                >
                    {{ pc.name }}
                </text>
            </g>
        </svg>
    </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(defineProps<{
    selectedIds?: string[],
    occupiedIds?: string[],
    computers?: any[],
    mapConfig?: any,
    viewbox?: string
}>(), {
    computers: () => [],
    selectedIds: () => [],
    occupiedIds: () => []
})

const emit = defineEmits(['toggle-seat', 'seat-error'])

const safeConfig = computed(() => {
    let data = props.mapConfig;
    if (!data) return null;
    if (typeof data === 'string') {
        try {
            data = JSON.parse(data);
            if (typeof data === 'string') data = JSON.parse(data);
        } catch { return null; }
    }
    return data;
})

const isSelected = (id: any) => props.selectedIds.includes(id.toString())
const isOccupied = (id: any) => props.occupiedIds.includes(id.toString())
const handleClick = (id: string) => isOccupied(id) ? emit('seat-error') : emit('toggle-seat', id)
</script>

<style scoped>
.map-wrapper {
    background-image: radial-gradient(circle at 50% 50%, rgba(34, 197, 94, 0.03) 0%, transparent 70%);
}
</style>
