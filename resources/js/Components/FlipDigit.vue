<script setup lang="ts">
import { nextTick, onUnmounted, ref } from 'vue'

const props = defineProps<{
    value: number
}>()

const FLIP_MS = 300

const current = ref(props.value)
const next = ref(props.value)
const flipping = ref(false)

let running = false
let flipTimer: ReturnType<typeof setTimeout> | null = null

function clearFlipTimer() {
    if (flipTimer) {
        clearTimeout(flipTimer)
        flipTimer = null
    }
}

function flipOnce(to: number): Promise<void> {
    return new Promise((resolve) => {
        next.value = to
        flipping.value = true
        clearFlipTimer()
        flipTimer = setTimeout(() => {
            current.value = to
            flipping.value = false
            flipTimer = null
            resolve()
        }, FLIP_MS)
    })
}

async function play() {
    if (running) return
    running = true
    const start = current.value
    for (let i = 1; i <= 10; i++) {
        flipping.value = false
        await nextTick()
        await flipOnce((start + i) % 10)
    }
    running = false
}

onUnmounted(() => {
    running = false
    clearFlipTimer()
})

defineExpose({ play })
</script>

<template>
    <div class="flip-unit">
        <div class="leaf leaf-top leaf-static">
            <span class="glyph">{{ next }}</span>
        </div>
        <div class="leaf leaf-bottom leaf-static">
            <span class="glyph">{{ current }}</span>
        </div>
        <div class="leaf leaf-top leaf-flap flap-front" :class="{ 'is-flip': flipping }">
            <span class="glyph">{{ current }}</span>
        </div>
        <div class="leaf leaf-bottom leaf-flap flap-back" :class="{ 'is-flip': flipping }">
            <span class="glyph">{{ next }}</span>
        </div>
        <span class="seam" />
    </div>
</template>

<style scoped>
.flip-unit {
    position: relative;
    width: calc(var(--brand) * 0.72);
    height: var(--brand);
    border: 1px solid rgba(34, 197, 94, 0.38);
    border-radius: 0.4rem;
    background: #050505;
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.08),
        0 0 12px rgba(34, 197, 94, 0.08);
    overflow: hidden;
    perspective: calc(var(--brand) * 3.2);
    transform-style: preserve-3d;
    flex-shrink: 0;
}
.leaf {
    position: absolute;
    left: 0;
    width: 100%;
    height: 50%;
    overflow: hidden;
}
.leaf-top { top: 0; }
.leaf-bottom { bottom: 0; }
.leaf-static.leaf-top {
    background: linear-gradient(180deg, rgba(34, 197, 94, 0.16) 0%, rgba(10, 10, 10, 0.92) 100%);
    z-index: 1;
}
.leaf-static.leaf-bottom {
    background: linear-gradient(180deg, rgba(8, 8, 8, 0.98) 0%, #000 100%);
    z-index: 1;
}
.leaf-flap {
    z-index: 3;
    backface-visibility: hidden;
    -webkit-backface-visibility: hidden;
}
.flap-front {
    background: linear-gradient(180deg, rgba(34, 197, 94, 0.18) 0%, rgba(10, 10, 10, 0.95) 100%);
    transform-origin: 50% 100%;
    z-index: 4;
}
.flap-back {
    background: linear-gradient(180deg, rgba(8, 8, 8, 0.98) 0%, #000 100%);
    transform-origin: 50% 0%;
    transform: rotateX(90deg);
    z-index: 3;
}
.flap-front.is-flip {
    animation: flap-front 150ms ease-in forwards;
}
.flap-back.is-flip {
    animation: flap-back 150ms ease-out 150ms forwards;
}
@keyframes flap-front {
    from { transform: rotateX(0deg); }
    to { transform: rotateX(-90deg); }
}
@keyframes flap-back {
    from { transform: rotateX(90deg); }
    to { transform: rotateX(0deg); }
}
.glyph {
    position: absolute;
    left: 0;
    width: 100%;
    height: var(--brand);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #000;
    -webkit-text-stroke: calc(var(--brand) * 0.028) #22c55e;
    paint-order: stroke fill;
    font-family: Arial, Helvetica, sans-serif;
    font-weight: 900;
    font-style: italic;
    font-size: calc(var(--brand) * 0.62);
    line-height: 1;
    text-shadow: 0 0 8px rgba(34, 197, 94, 0.55);
}
.leaf-top .glyph { top: 0; }
.leaf-bottom .glyph { top: -100%; }
.seam {
    position: absolute;
    left: 0;
    right: 0;
    top: 50%;
    height: 1px;
    background: rgba(0, 0, 0, 0.78);
    box-shadow: 0 1px 0 rgba(255, 255, 255, 0.06);
    z-index: 6;
    pointer-events: none;
}
</style>
