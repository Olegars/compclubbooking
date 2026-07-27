<template>
    <div class="map-wrapper w-full h-full relative rounded-[38px] overflow-hidden border border-white/5 bg-[#020202] flex items-center justify-center">
        <svg
            :viewBox="displayViewbox"
            preserveAspectRatio="xMidYMid meet"
            class="block w-full h-full select-none"
        >
            <defs>
                <filter id="wall-glow" x="-50%" y="-50%" width="200%" height="200%">
                    <feGaussianBlur stdDeviation="0.25" result="blur" />
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
                    stroke-width="0.2"
                    stroke-linejoin="miter"
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
                    stroke-width="0.15"
                    rx="0"
                />
            </g>

            <g v-if="visibleLabels.length" class="labels-layer">
                <text
                    v-for="(l, i) in visibleLabels" :key="'l-'+i"
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

            <g v-for="pc in computers" :key="'pc-'+pc.id"
               :class="isOccupied(pc.id) ? 'cursor-not-allowed' : 'cursor-pointer group'"
               @click="handleClick(pc)">
                <rect
                    :x="Number(pc.x)" :y="Number(pc.y)"
                    width="6" height="4.5"
                    rx="0"
                    :fill="seatFill(pc)"
                    :stroke="seatStroke(pc)"
                    :stroke-width="isOccupied(pc.id) ? 0.15 : 0.2"
                    :opacity="isOccupied(pc.id) ? 0.55 : 1"
                    class="transition-colors duration-300"
                    :class="isOccupied(pc.id) ? '' : 'group-hover:stroke-white'"
                />
                <text
                    :x="Number(pc.x) + 3" :y="Number(pc.y) + 3.1"
                    font-size="2"
                    font-weight="900"
                    font-family="Arial, sans-serif"
                    text-anchor="middle"
                    :fill="seatText(pc)"
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

const emit = defineEmits<{
    (e: 'toggle-seat', id: string): void
    (e: 'seat-error'): void
}>()

const PC_W = 6
const PC_H = 4.5
const FIT_PAD = 8

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

const isPlaceholderLabel = (content: unknown) => {
    const text = String(content ?? '').trim().toUpperCase()
    return text === '' || text === 'ТЕКСТ' || text === 'TEXT'
}

const visibleLabels = computed(() =>
    (safeConfig.value?.labels || []).filter((l: any) => l && !isPlaceholderLabel(l.content))
)

const fallbackViewbox = computed(() =>
    String(safeConfig.value?.viewbox || props.viewbox || '-10 -10 120 200').trim()
)

const contentBounds = computed(() => {
    let minX = Infinity
    let minY = Infinity
    let maxX = -Infinity
    let maxY = -Infinity
    let found = false

    const include = (x: number, y: number, w = 0, h = 0) => {
        if (![x, y, w, h].every(Number.isFinite)) return
        found = true
        minX = Math.min(minX, x)
        minY = Math.min(minY, y)
        maxX = Math.max(maxX, x + w)
        maxY = Math.max(maxY, y + h)
    }

    for (const wall of safeConfig.value?.walls || []) {
        const nums = String(wall?.d || '').match(/-?\d*\.?\d+/g)?.map(Number) || []
        for (let i = 0; i + 1 < nums.length; i += 2) include(nums[i], nums[i + 1])
    }

    for (const z of safeConfig.value?.zoneRects || []) {
        include(Number(z.x), Number(z.y), Number(z.w), Number(z.h))
    }

    for (const pc of props.computers || []) {
        include(Number(pc.x), Number(pc.y), PC_W, PC_H)
    }

    if (!found) return null

    const pad = FIT_PAD
    return {
        x: minX - pad,
        y: minY - pad,
        w: Math.max(maxX - minX + pad * 2, 20),
        h: Math.max(maxY - minY + pad * 2, 20),
    }
})

const displayViewbox = computed(() => {
    const b = contentBounds.value
    if (b) return `${b.x} ${b.y} ${b.w} ${b.h}`
    return fallbackViewbox.value
})

const kindOf = (pc: any) => String(pc?.kind || 'pc')
const accentOf = (pc: any) => {
    const kind = kindOf(pc)
    if (kind === 'tv') return '#a855f7'
    if (kind === 'ps5') return '#3b82f6'
    return '#22c55e'
}

const isSelected = (id: any) => props.selectedIds.includes(id.toString())
const isOccupied = (id: any) => props.occupiedIds.includes(id.toString())

const seatFill = (pc: any) => {
    if (isOccupied(pc.id)) return '#1a1a1a'
    if (isSelected(pc.id)) return accentOf(pc)
    return '#001100'
}

const seatStroke = (pc: any) => {
    if (isOccupied(pc.id)) return '#444'
    if (isSelected(pc.id)) return '#fff'
    return accentOf(pc)
}

const seatText = (pc: any) => {
    if (isOccupied(pc.id)) return '#666'
    if (isSelected(pc.id)) return '#000'
    return accentOf(pc)
}

const handleClick = (pc: any) => {
    if (isOccupied(pc.id)) {
        emit('seat-error')
        return
    }
    emit('toggle-seat', pc.id.toString())
}
</script>

<style scoped>
.map-wrapper {
    background-image: radial-gradient(circle at 50% 50%, rgba(34, 197, 94, 0.03) 0%, transparent 70%);
}
</style>
