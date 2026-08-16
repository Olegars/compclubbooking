<script setup lang="ts">
import { onMounted, onUnmounted, ref, watch } from 'vue'

const props = withDefaults(defineProps<{
    value: number
    delay?: number
    tick?: number
}>(), {
    delay: 0,
    tick: 0,
})

const HALF_MS = 180
const FLIP_MS = HALF_MS * 2 + 30

const current = ref(props.value)
const incoming = ref(props.value)
const animating = ref(false)

let cycleId = 0
let timers: ReturnType<typeof setTimeout>[] = []

function wait(ms: number) {
    return new Promise<void>((resolve) => {
        const id = setTimeout(resolve, ms)
        timers.push(id)
    })
}

function clearTimers() {
    timers.forEach(clearTimeout)
    timers = []
}

async function flipTo(n: number, my: number) {
    if (my !== cycleId) return
    incoming.value = n
    animating.value = false
    await wait(20)
    if (my !== cycleId) return
    animating.value = true
    await wait(FLIP_MS)
    if (my !== cycleId) return
    current.value = n
    animating.value = false
}

async function runCycle() {
    const my = ++cycleId
    clearTimers()
    animating.value = false
    const start = current.value
    for (let i = 1; i <= 10; i++) {
        if (my !== cycleId) return
        await flipTo((start + i) % 10, my)
    }
}

function schedule() {
    const id = setTimeout(() => { void runCycle() }, props.delay)
    timers.push(id)
}

onMounted(() => {
    schedule()
})

watch(() => props.tick, (now, prev) => {
    if (now !== prev) {
        clearTimers()
        cycleId++
        schedule()
    }
})

onUnmounted(() => {
    cycleId++
    clearTimers()
})
</script>

<template>
    <div class="flip-stage">
        <div class="flip-unit" :class="{ 'is-animating': animating }">
            <div class="panel top static">
                <span class="glyph">{{ incoming }}</span>
            </div>
            <div class="panel bottom static">
                <span class="glyph">{{ current }}</span>
            </div>
            <div class="panel top card card-top">
                <span class="glyph">{{ current }}</span>
            </div>
            <div class="panel bottom card card-bottom">
                <span class="glyph">{{ incoming }}</span>
            </div>
            <span class="seam" />
        </div>
    </div>
</template>

<style scoped>
.flip-stage {
    width: calc(var(--brand, 4.5rem) * 0.72);
    height: var(--brand, 4.5rem);
    perspective: 280px;
    flex-shrink: 0;
}
.flip-unit {
    position: relative;
    width: 100%;
    height: 100%;
    border: 1px solid rgba(34, 197, 94, 0.38);
    border-radius: 0.4rem;
    background: #050505;
    box-shadow:
        inset 0 1px 0 rgba(255, 255, 255, 0.08),
        0 0 12px rgba(34, 197, 94, 0.08);
    overflow: hidden;
}
.panel {
    position: absolute;
    left: 0;
    width: 100%;
    height: 50%;
    overflow: hidden;
}
.top { top: 0; }
.bottom { bottom: 0; }
.static.top {
    background: linear-gradient(180deg, rgba(34, 197, 94, 0.16) 0%, rgba(10, 10, 10, 0.92) 100%);
    z-index: 1;
}
.static.bottom {
    background: linear-gradient(180deg, rgba(8, 8, 8, 0.98) 0%, #000 100%);
    z-index: 1;
}
.card {
    z-index: 3;
    backface-visibility: hidden;
    -webkit-backface-visibility: hidden;
}
.card-top {
    background: linear-gradient(180deg, rgba(34, 197, 94, 0.2) 0%, rgba(10, 10, 10, 0.95) 100%);
    transform-origin: 50% 100%;
    z-index: 4;
}
.card-bottom {
    background: linear-gradient(180deg, rgba(8, 8, 8, 0.98) 0%, #000 100%);
    transform-origin: 50% 0%;
    transform: rotateX(90deg);
    z-index: 3;
}
.flip-unit.is-animating .card-top {
    animation: card-top-flip 180ms ease-in forwards;
}
.flip-unit.is-animating .card-bottom {
    animation: card-bottom-flip 180ms ease-out 180ms forwards;
}
@keyframes card-top-flip {
    from { transform: rotateX(0deg); }
    to { transform: rotateX(-90deg); }
}
@keyframes card-bottom-flip {
    from { transform: rotateX(90deg); }
    to { transform: rotateX(0deg); }
}
.glyph {
    position: absolute;
    left: 0;
    width: 100%;
    height: var(--brand, 4.5rem);
    display: flex;
    align-items: center;
    justify-content: center;
    color: #000;
    -webkit-text-fill-color: #000;
    -webkit-text-stroke: 0;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    text-rendering: geometricPrecision;
    font-family: Arial, Helvetica, sans-serif;
    font-weight: 900;
    font-style: normal;
    font-size: calc(var(--brand, 4.5rem) * 0.62);
    line-height: 1;
    /* Контур через text-shadow: Blink рвёт -webkit-text-stroke в зубцы. */
    text-shadow:
        -2px -2px 0 #22c55e, 0 -2px 0 #22c55e, 2px -2px 0 #22c55e,
        2px 0 0 #22c55e, 2px 2px 0 #22c55e, 0 2px 0 #22c55e,
        -2px 2px 0 #22c55e, -2px 0 0 #22c55e,
        0 0 8px rgba(34, 197, 94, 0.55);
}
.top .glyph { top: 0; }
.bottom .glyph { top: -100%; }
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
