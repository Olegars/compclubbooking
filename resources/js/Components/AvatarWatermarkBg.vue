<script setup lang="ts">
import { onMounted, onUnmounted, ref } from 'vue'

const AVATARS = Array.from({ length: 10 }, (_, i) => `/images/avatars/avatar_${i + 1}.png`)

type Jitter = { dx: number; dy: number; rot: number; sc: number; op: number }
type Tile = {
    src: string
    x: number
    y: number
    w: number
    h: number
    rot: number
    op: number
}

const host = ref<HTMLElement | null>(null)
const tiles = ref<Tile[]>([])
const order = ref<number[]>([])
const jitter = ref<Jitter[]>([])

function shuffle<T>(arr: T[]): T[] {
    const a = arr.slice()
    for (let i = a.length - 1; i > 0; i--) {
        const j = Math.floor(Math.random() * (i + 1))
        const t = a[i]
        a[i] = a[j]
        a[j] = t
    }
    return a
}

function rebuild() {
    const el = host.value
    if (!el || order.value.length === 0 || jitter.value.length === 0) return

    const width = el.clientWidth || window.innerWidth
    const height = el.clientHeight || window.innerHeight
    if (width < 80 || height < 80) {
        tiles.value = []
        return
    }

    const cellW = Math.max(240, Math.round(width / 5.1))
    const cellH = Math.max(280, Math.round(height / 3.15))
    const cols = Math.max(4, Math.ceil(width / cellW) + 1)
    const rows = Math.max(3, Math.ceil(height / cellH) + 1)
    const next: Tile[] = []

    for (let index = 0; index < cols * rows; index++) {
        const j = jitter.value[index % jitter.value.length]
        const col = index % cols
        const row = Math.floor(index / cols)
        const srcIndex = order.value[index % order.value.length]
        next.push({
            src: AVATARS[srcIndex],
            x: col * cellW + j.dx * cellW * 0.18 - cellW * 0.1,
            y: row * cellH + j.dy * cellH * 0.18 - cellH * 0.1,
            w: cellW * j.sc,
            h: cellH * j.sc,
            rot: j.rot,
            op: j.op,
        })
    }

    tiles.value = next
}

let resizeTimer = 0
function onResize() {
    window.clearTimeout(resizeTimer)
    resizeTimer = window.setTimeout(rebuild, 80)
}

onMounted(() => {
    order.value = shuffle(AVATARS.map((_, i) => i))
    const jits: Jitter[] = []
    for (let k = 0; k < 72; k++) {
        jits.push({
            dx: Math.random() - 0.5,
            dy: Math.random() - 0.5,
            rot: (Math.random() - 0.5) * 16,
            sc: 0.84 + Math.random() * 0.24,
            op: 0.09 + Math.random() * 0.07,
        })
    }
    jitter.value = jits
    rebuild()
    window.addEventListener('resize', onResize)
})

onUnmounted(() => {
    window.clearTimeout(resizeTimer)
    window.removeEventListener('resize', onResize)
})
</script>

<template>
    <div ref="host" class="avatar-watermark" aria-hidden="true">
        <img
            v-for="(tile, i) in tiles"
            :key="i"
            :src="tile.src"
            alt=""
            decoding="async"
            draggable="false"
            :style="{
                left: tile.x + 'px',
                top: tile.y + 'px',
                width: tile.w + 'px',
                height: tile.h + 'px',
                opacity: tile.op,
                transform: `rotate(${tile.rot}deg)`,
            }"
        >
    </div>
</template>

<style scoped>
.avatar-watermark {
    position: fixed;
    inset: 0;
    overflow: hidden;
    pointer-events: none;
    z-index: 0;
}
.avatar-watermark img {
    position: absolute;
    object-fit: cover;
    user-select: none;
}
</style>
