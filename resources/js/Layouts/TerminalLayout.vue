<script setup lang="ts">
import { ref, onMounted, nextTick } from 'vue'

const isRolling = ref(false)

const triggerRoll = async () => {
    isRolling.value = false
    await nextTick()
    // Даем Vue один тик на сброс классов перед рестартом анимации
    setTimeout(() => { isRolling.value = true }, 30)
}

onMounted(() => {
    // Автостарт при загрузке
    setTimeout(() => { triggerRoll() }, 500)
    // Повтор каждые 10 секунд для живости интерфейса
    setInterval(() => { triggerRoll() }, 10000)
})
</script>

<template>
    <main class="min-h-screen bg-[#050505] text-slate-200 font-mono relative flex flex-col select-none touch-auto overflow-x-hidden">

        <div class="fixed inset-0 pointer-events-none z-[100] opacity-[0.03] bg-[linear-gradient(rgba(18,16,16,0)_50%,rgba(0,0,0,0.25)_50%),linear-gradient(90deg,rgba(255,0,0,0.06),rgba(0,255,0,0.02),rgba(0,0,0,0.06))] bg-[length:100%_4px,3px_100%]"></div>

        <header class="border-b border-white/5 bg-black/80 backdrop-blur-2xl sticky top-0 z-[50] py-10 flex-shrink-0">
            <div class="max-w-[1600px] mx-auto px-6 flex flex-col items-center relative">

                <div class="flex flex-col items-center gap-4 cursor-pointer group" @click="triggerRoll">
                    <h1 class="flex items-center justify-center scale-75 lg:scale-90 transition-transform group-hover:scale-[0.92]">
                        <span class="sector-neon text-[80px] lg:text-[110px] uppercase leading-none tracking-tighter italic font-bomber">
                            Sector
                        </span>

                        <div class="slot-container ml-6 lg:ml-10 mt-2 lg:mt-4 flex items-center justify-center relative border border-white/5 rounded-xl overflow-hidden bg-black/50 shadow-inner">
                            <div class="slot-inner flex w-full h-full">
                                <div v-for="(digit, index) in [0, 4, 5, 1]" :key="index" class="digit-box border-r border-white/5 last:border-0">
                                    <div
                                        class="digit-strip"
                                        :class="[`strip-${digit}`, { 'roll-active': isRolling }]"
                                        :style="{ animationDelay: isRolling ? `${index * 150}ms` : '0ms' }"
                                    >
                                        <span v-for="n in 20" :key="n" class="d-cell font-mono font-black italic">
                                            {{ (n - 1) % 10 }}
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </h1>
                    <span class="text-[10px] text-[#22c55e] opacity-40 uppercase tracking-[0.8em] mt-2 italic font-black font-sans">
                        System Terminal // Node 0451 v.2.6
                    </span>
                </div>
            </div>
        </header>

        <div class="flex-grow w-full py-6 flex flex-col items-center px-6 relative z-[20]">
            <div class="w-full max-w-[1500px]">
                <slot />
            </div>
        </div>
    </main>
</template>

<style scoped>
.font-bomber { font-family: 'BomberEscort', sans-serif; }

/* Эффект неонового текста РЕАКТОР */
.sector-neon {
    color: #000;
    -webkit-text-stroke: 1.2px #22c55e;
    text-shadow: 0 0 5px rgba(34, 197, 94, 0.8), 0 0 20px rgba(34, 197, 94, 0.4);
    filter: brightness(1.1);
}

.slot-container { width: 200px; height: 70px; }
@media (min-width: 1024px) { .slot-container { width: 264px; height: 92px; } }

.digit-box { width: 25%; height: 100%; position: relative; overflow: hidden; }
.digit-strip { display: flex; flex-direction: column; width: 100%; will-change: transform; }

/* Высота ячейки должна совпадать с контейнером для идеального центрирования */
.d-cell {
    height: 70px;
    display: flex;
    align-items: center;
    justify-content: center;
    color: #000;
    -webkit-text-stroke: 1.5px #22c55e;
    font-size: 3rem;
}

@media (min-width: 1024px) {
    .d-cell {
        height: 92px;
        -webkit-text-stroke: 2.5px #22c55e;
        font-size: 4.5rem;
    }
}

/* Стартовые позиции для каждой цифры 0-4-5-1 */
.strip-0 { transform: translateY(0); }
.strip-4 { transform: translateY(calc(-92px * 4)); }
.strip-5 { transform: translateY(calc(-92px * 5)); }
.strip-1 { transform: translateY(calc(-92px * 1)); }

@media (max-width: 1024px) {
    .strip-4 { transform: translateY(calc(-70px * 4)); }
    .strip-5 { transform: translateY(calc(-70px * 5)); }
    .strip-1 { transform: translateY(calc(-70px * 1)); }
}

.roll-active {
    animation: roll 2s cubic-bezier(0.45, 0.05, 0.55, 0.95) both;
}

@keyframes roll {
    /* Прокручиваем на 10 цифр вперед (полный круг) */
    to { transform: translateY(calc(-92px * 10)); }
}

@media (max-width: 1024px) {
    @keyframes roll {
        to { transform: translateY(calc(-70px * 10)); }
    }
}
</style>
